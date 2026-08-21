<?php

namespace App\Support;

use App\Domain\Projects\Queries\ProjectHomeSummaryQuery;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Collection;

final class NewDayHomeSnapshot
{
    public function __construct(
        private readonly OfficeDashboardSnapshot $portal,
        private readonly ProjectHomeSummaryQuery $projects,
    ) {}

    public function for(Organization $organization, OrganizationMembership $membership): array
    {
        $membership->loadMissing(['capabilityOverrides', 'roles.capabilities']);
        $portal = $this->portal->for($organization, $membership);
        $projects = $membership->hasCapability('projects.view') ? $this->projects->for($organization) : null;

        return [
            'portal' => $portal,
            'projects' => $projects,
            'search_visible' => $membership->hasCapability('customers.view'),
            'launchers' => $this->launchers($membership),
            'attention_items' => $this->attention($organization, $membership, $portal, $projects),
        ];
    }

    private function launchers(OrganizationMembership $membership): array
    {
        return collect([
            $membership->hasCapability('service_tickets.view') ? [
                'label' => 'Service Operations',
                'description' => 'Dispatch, Service Tickets, Field & Review',
                'route' => route('office.dispatch.index'),
                'eyebrow' => 'Portal',
            ] : null,
            $membership->hasCapability('projects.view') ? [
                'label' => 'Projects',
                'description' => 'Projects, Tasks & Milestones',
                'route' => route('office.projects.index'),
                'eyebrow' => 'Workspace',
            ] : null,
        ])->filter()->values()->all();
    }

    private function attention(Organization $organization, OrganizationMembership $membership, array $portal, ?array $projects): Collection
    {
        $items = collect();

        foreach ($portal['follow_up'] ?? [] as $item) {
            $ticket = $item['ticket'];
            $items->push($this->item(3, 'portal', 'service_follow_up', $ticket->ticket_number.' · '.$ticket->title, $ticket->customer->display_name, implode(' · ', $item['labels']), 'attention', $ticket->created_at, route('office.service-tickets.show', $ticket), $ticket->id));
        }

        if ($membership->hasCapability('closeouts.inspect')) {
            Closeout::query()->where('organization_id', $organization->id)->where('status', 'submitted')->whereDoesntHave('reviews')
                ->with('visit.serviceTicket:id,organization_id,ticket_number,title')->orderBy('submitted_at')->orderBy('id')->limit(3)->get()
                ->each(fn (Closeout $closeout) => $items->push($this->item(3, 'portal', 'awaiting_review', 'Review '.$closeout->visit->serviceTicket->ticket_number, $closeout->visit->serviceTicket->title, 'Awaiting review', 'attention', $closeout->submitted_at, route('office.closeout-reviews.show', $closeout), $closeout->id)));
        }

        if ($membership->hasCapability('billing_handoffs.view')) {
            BillingHandoff::query()->forOrganization($organization->id)->where('status', 'ready')->whereNull('current_invoice_id')
                ->with('serviceTicket:id,organization_id,ticket_number,title')->oldest()->limit(3)->get()
                ->each(fn (BillingHandoff $handoff) => $items->push($this->item(3, 'portal', 'ready_to_invoice', 'Invoice '.$handoff->serviceTicket->ticket_number, $handoff->serviceTicket->title, 'Ready to invoice', 'attention', $handoff->created_at, route('office.invoices.index', ['workspace' => 'ready_to_invoice']), $handoff->id)));
        }

        if ($membership->hasCapability('invoices.view')) {
            foreach ($portal['billing']['invoices']['oldest_overdue'] ?? [] as $invoice) {
                $items->push($this->item(0, 'portal', 'overdue_invoice', $invoice->invoice_number, $invoice->customer->display_name, 'Overdue · $'.number_format($invoice->dashboard_balance_cents / 100, 2), 'critical', $invoice->due_on, route('office.invoices.show', $invoice), $invoice->id));
            }
        }

        if ($projects !== null) {
            foreach ($projects['overdue_tasks'] as $task) {
                $items->push($this->item(1, 'projects', 'overdue_task', $task->title, $task->project->project_number.' · '.$task->project->name, 'Overdue task', 'critical', $task->due_on, route('office.projects.show', $task->project).'#tasks', $task->id));
            }
            foreach ($projects['blocked_tasks'] as $task) {
                $items->push($this->item(2, 'projects', 'blocked_task', $task->title, $task->project->project_number.' · '.$task->project->name, 'Blocked task', 'attention', $task->updated_at, route('office.projects.show', $task->project).'#tasks', $task->id));
            }
            foreach ($projects['upcoming_milestones'] as $milestone) {
                $items->push($this->item(4, 'projects', 'upcoming_milestone', $milestone->name, $milestone->project->project_number.' · '.$milestone->project->name, 'Milestone · '.$milestone->target_on->format('M j'), 'upcoming', $milestone->target_on, route('office.projects.show', $milestone->project).'#milestones', $milestone->id));
            }
        }

        return $items->sortBy(fn (array $item) => sprintf('%02d|%s|%s|%010d', $item['priority'], $item['sort_at']?->format('Y-m-d H:i:s') ?? '9999-12-31 23:59:59', $item['kind'], $item['stable_id']))->take(12)->values();
    }

    private function item(int $priority, string $domain, string $kind, string $title, string $context, string $badge, string $severity, $sortAt, string $route, int $stableId): array
    {
        return compact('priority', 'domain', 'kind', 'title', 'context', 'badge', 'severity', 'route') + [
            'sort_at' => $sortAt,
            'stable_id' => $stableId,
        ];
    }
}
