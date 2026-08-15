<?php

namespace App\Support;

use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Invoice;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicket;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OfficeDashboardSnapshot
{
    private const IN_PROGRESS_STATUSES = ['en_route', 'on_site', 'pending_closeout'];

    private const REMAINING_STATUSES = ['planned', 'scheduled', 'assigned'];

    private const COMPLETED_STATUSES = ['approved', 'customer_unavailable'];

    public function for(Organization $organization, OrganizationMembership $membership): array
    {
        $membership->loadMissing(['capabilityOverrides', 'roles.capabilities']);
        $localNow = CarbonImmutable::now($organization->timezone);
        $dayStart = $localNow->startOfDay()->utc();
        $dayEnd = $localNow->addDay()->startOfDay()->utc();

        $canViewOperations = $membership->hasCapability('service_tickets.view');
        $canViewReview = $membership->hasCapability('closeouts.inspect');
        $canViewHandoffs = $membership->hasCapability('billing_handoffs.view');
        $canViewInvoices = $membership->hasCapability('invoices.view');
        $canViewHealth = $membership->hasCapability('operations.health.view');

        $operations = $canViewOperations
            ? $this->operations($organization, $dayStart, $dayEnd, $localNow)
            : null;
        $review = $canViewReview ? $this->review($organization) : null;
        $readyToInvoice = $canViewHandoffs ? $this->readyToInvoice($organization) : null;
        $invoices = $canViewInvoices ? $this->invoices($organization, $localNow) : null;

        return [
            'generated_at' => $localNow,
            'local_date' => $localNow->startOfDay(),
            'visibility' => [
                'operations' => $canViewOperations,
                'review' => $canViewReview,
                'handoffs' => $canViewHandoffs,
                'invoices' => $canViewInvoices,
                'health' => $canViewHealth,
            ],
            'attention' => [
                'unscheduled' => $operations['unscheduled'] ?? null,
                'awaiting_review' => $review,
                'ready_to_invoice' => $readyToInvoice,
                'overdue' => $invoices === null ? null : [
                    'count' => $invoices['overdue_count'],
                    'amount_cents' => $invoices['overdue_cents'],
                ],
            ],
            'today' => $operations['today'] ?? null,
            'follow_up' => $operations['follow_up'] ?? null,
            'billing' => ($canViewHandoffs || $canViewInvoices) ? [
                'ready_to_invoice' => $readyToInvoice,
                'invoices' => $invoices,
            ] : null,
            'health' => $canViewHealth ? $this->health($organization) : null,
            'actions' => $this->actions($membership),
        ];
    }

    private function operations(
        Organization $organization,
        CarbonImmutable $dayStart,
        CarbonImmutable $dayEnd,
        CarbonImmutable $localNow,
    ): array {
        $ticketBacklog = ServiceTicket::query()
            ->forOrganization($organization->id)
            ->whereIn('status', ['open', 'on_hold'])
            ->whereDoesntHave('visits')
            ->count();
        $visitBacklog = Visit::query()
            ->forOrganization($organization->id)
            ->where('status', '!=', 'canceled')
            ->whereNull('scheduled_start_at')
            ->count();

        $todayCounts = Visit::query()
            ->forOrganization($organization->id)
            ->where('status', '!=', 'canceled')
            ->where('scheduled_start_at', '>=', $dayStart)
            ->where('scheduled_start_at', '<', $dayEnd)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        $todayVisits = Visit::query()
            ->forOrganization($organization->id)
            ->where('status', '!=', 'canceled')
            ->where('scheduled_start_at', '>=', $dayStart)
            ->where('scheduled_start_at', '<', $dayEnd)
            ->with([
                'serviceTicket.customer',
                'assignments.membership.user',
            ])
            ->orderBy('scheduled_start_at')
            ->orderBy('id')
            ->limit(8)
            ->get();

        return [
            'unscheduled' => [
                'tickets' => $ticketBacklog,
                'visits' => $visitBacklog,
                'total' => $ticketBacklog + $visitBacklog,
            ],
            'today' => [
                'total' => $todayCounts->sum(),
                'in_progress' => $this->sumStatuses($todayCounts, self::IN_PROGRESS_STATUSES),
                'remaining' => $this->sumStatuses($todayCounts, self::REMAINING_STATUSES),
                'completed' => $this->sumStatuses($todayCounts, self::COMPLETED_STATUSES),
                'visits' => $todayVisits,
            ],
            'follow_up' => $this->followUp($organization, $localNow),
        ];
    }

    private function review(Organization $organization): array
    {
        $row = Closeout::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'submitted')
            ->whereDoesntHave('reviews')
            ->selectRaw('COUNT(*) as aggregate, MIN(submitted_at) as oldest_submitted_at')
            ->first();

        return [
            'count' => (int) ($row?->aggregate ?? 0),
            'oldest_submitted_at' => $row?->oldest_submitted_at
                ? CarbonImmutable::parse($row->oldest_submitted_at, 'UTC')->timezone($organization->timezone)
                : null,
        ];
    }

    private function readyToInvoice(Organization $organization): int
    {
        return BillingHandoff::query()
            ->forOrganization($organization->id)
            ->where('status', 'ready')
            ->whereNull('current_invoice_id')
            ->count();
    }

    private function invoices(Organization $organization, CarbonImmutable $localNow): array
    {
        $balance = InvoiceBalanceExpressions::balance();
        $today = $localNow->toDateString();
        $summary = Invoice::query()
            ->forOrganization($organization->id)
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count")
            ->selectRaw("SUM(CASE WHEN status = 'ready_for_review' THEN 1 ELSE 0 END) as ready_for_review_count")
            ->selectRaw("SUM(CASE WHEN status = 'issued' AND {$balance} > 0 THEN 1 ELSE 0 END) as issued_open_count")
            ->selectRaw("SUM(CASE WHEN status = 'issued' AND {$balance} > 0 THEN {$balance} ELSE 0 END) as open_ar_cents")
            ->selectRaw("SUM(CASE WHEN status = 'issued' AND due_on IS NOT NULL AND due_on < ? AND {$balance} > 0 THEN 1 ELSE 0 END) as overdue_count", [$today])
            ->selectRaw("SUM(CASE WHEN status = 'issued' AND due_on IS NOT NULL AND due_on < ? AND {$balance} > 0 THEN {$balance} ELSE 0 END) as overdue_cents", [$today])
            ->first();

        $oldestOverdue = Invoice::query()
            ->forOrganization($organization->id)
            ->where('status', 'issued')
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', $today)
            ->whereRaw("{$balance} > 0")
            ->with('customer')
            ->select('invoices.*')
            ->selectRaw("{$balance} as dashboard_balance_cents")
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit(3)
            ->get();

        return [
            'draft_count' => (int) ($summary?->draft_count ?? 0),
            'ready_for_review_count' => (int) ($summary?->ready_for_review_count ?? 0),
            'issued_open_count' => (int) ($summary?->issued_open_count ?? 0),
            'open_ar_cents' => (int) ($summary?->open_ar_cents ?? 0),
            'overdue_count' => (int) ($summary?->overdue_count ?? 0),
            'overdue_cents' => (int) ($summary?->overdue_cents ?? 0),
            'oldest_overdue' => $oldestOverdue,
        ];
    }

    private function followUp(Organization $organization, CarbonImmutable $localNow): Collection
    {
        $recentUnavailable = $localNow->utc()->subDays(7);
        $tickets = ServiceTicket::query()
            ->forOrganization($organization->id)
            ->whereIn('status', ['open', 'on_hold'])
            ->where(function ($query) use ($recentUnavailable): void {
                $query->where(function ($query): void {
                    $query->whereIn('priority', ['urgent', 'high'])->whereDoesntHave('visits');
                })->orWhereIn('purpose', ['callback', 'warranty'])
                    ->orWhereHas('visits', fn ($visits) => $visits->where('status', 'returned_for_correction'))
                    ->orWhereHas('visits', fn ($visits) => $visits->where('status', 'customer_unavailable')->where('updated_at', '>=', $recentUnavailable));
            })
            ->with([
                'customer',
                'visits' => fn ($visits) => $visits
                    ->where(function ($query) use ($recentUnavailable): void {
                        $query->where('status', 'returned_for_correction')
                            ->orWhere(fn ($query) => $query->where('status', 'customer_unavailable')->where('updated_at', '>=', $recentUnavailable));
                    })
                    ->orderByDesc('updated_at'),
            ])
            ->withCount('visits')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->oldest('created_at')
            ->limit(8)
            ->get();

        return $tickets->map(function (ServiceTicket $ticket): array {
            $labels = [];
            if (in_array($ticket->priority, ['urgent', 'high'], true) && $ticket->visits_count === 0) {
                $labels[] = ucfirst($ticket->priority).' · no Visit';
            }
            if ($ticket->purpose === 'callback') {
                $labels[] = 'Callback';
            }
            if ($ticket->purpose === 'warranty') {
                $labels[] = 'Warranty';
            }
            if ($ticket->visits->contains('status', 'returned_for_correction')) {
                $labels[] = 'Returned for correction';
            }
            if ($ticket->visits->contains('status', 'customer_unavailable')) {
                $labels[] = 'Customer unavailable';
            }

            return ['ticket' => $ticket, 'labels' => array_values(array_unique($labels))];
        });
    }

    private function health(Organization $organization): array
    {
        $scope = fn ($query) => $query->where(fn ($query) => $query
            ->where('organization_id', $organization->id)
            ->orWhereNull('organization_id'));
        $summary = OperationalIncident::query()
            ->where($scope)
            ->where('status', 'open')
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw("SUM(CASE WHEN severity IN ('critical', 'error') THEN 1 ELSE 0 END) as high_count")
            ->first();

        return [
            'open_incidents' => (int) ($summary?->open_count ?? 0),
            'high_incidents' => (int) ($summary?->high_count ?? 0),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    private function actions(OrganizationMembership $membership): array
    {
        $actions = [];
        $definitions = [
            ['label' => 'New Service Ticket', 'route' => 'office.service-tickets.create', 'capability' => 'dispatch.manage', 'primary' => true],
            ['label' => 'Open Dispatch', 'route' => 'office.dispatch.index', 'capability' => 'service_tickets.view'],
            ['label' => 'Open Review', 'route' => 'office.closeout-reviews.index', 'capability' => 'closeouts.inspect'],
            ['label' => 'Open Billing', 'route' => $membership->hasCapability('invoices.view') ? 'office.invoices.index' : 'office.billing-handoffs.index', 'capability' => $membership->hasCapability('invoices.view') ? 'invoices.view' : 'billing_handoffs.view'],
            ['label' => 'New Invoice', 'route' => 'office.invoices.create', 'capability' => 'invoices.manage', 'primary' => true],
            ['label' => 'New Customer', 'route' => 'office.customers.create', 'capability' => 'customers.manage', 'primary' => true],
        ];
        foreach ($definitions as $definition) {
            if ($membership->hasCapability($definition['capability'])) {
                $actions[] = $definition;
            }
        }

        $primaryIndex = collect($actions)->search(fn (array $action): bool => (bool) ($action['primary'] ?? false));
        if ($primaryIndex !== false) {
            $actions[$primaryIndex]['is_primary'] = true;
        }

        return $actions;
    }

    private function sumStatuses(Collection $counts, array $statuses): int
    {
        return collect($statuses)->sum(fn (string $status): int => (int) $counts->get($status, 0));
    }
}
