<?php

namespace App\Support;

use App\Models\BillingHandoff;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\Visit;

class OperationalHealthScan
{
    public function __construct(private IncidentRecorder $incidents) {}

    public function scan(Organization $organization): array
    {
        $created = ['missing_handoffs' => 0, 'aging_handoffs' => 0, 'stuck_visits' => 0];

        ServiceTicket::query()->where('organization_id', $organization->id)->where('status', 'completed')
            ->whereDoesntHave('billingHandoff')->each(function (ServiceTicket $ticket) use ($organization, &$created): void {
                $this->incidents->record($organization, null, 'missing_billing_handoff', 'error', $ticket, ['ticket_id' => $ticket->id, 'status' => $ticket->status]);
                $created['missing_handoffs']++;
            });

        BillingHandoff::query()->where('organization_id', $organization->id)->where('status', 'ready')
            ->where('created_at', '<=', now()->subHours(config('operations.ready_handoff_warning_hours')))
            ->each(function (BillingHandoff $handoff) use ($organization, &$created): void {
                $this->incidents->record($organization, null, 'aging_billing_handoff', 'warning', $handoff, [
                    'handoff_id' => $handoff->id,
                    'ticket_id' => $handoff->service_ticket_id,
                    'age_hours' => (int) $handoff->created_at->diffInHours(now()),
                    'status' => $handoff->status,
                ]);
                $created['aging_handoffs']++;
            });

        Visit::query()->where('organization_id', $organization->id)
            ->where(function ($query): void {
                $query->where(fn ($query) => $query->whereIn('status', ['en_route', 'on_site'])->where('updated_at', '<=', now()->subHours(config('operations.active_visit_warning_hours'))))
                    ->orWhere(fn ($query) => $query->whereIn('status', ['pending_closeout', 'returned_for_correction'])->where('updated_at', '<=', now()->subHours(config('operations.closeout_warning_hours'))));
            })->each(function (Visit $visit) use ($organization, &$created): void {
                $this->incidents->record($organization, null, 'stuck_visit', 'warning', $visit, [
                    'visit_id' => $visit->id,
                    'ticket_id' => $visit->service_ticket_id,
                    'state' => $visit->status,
                    'age_hours' => (int) $visit->updated_at->diffInHours(now()),
                ]);
                $created['stuck_visits']++;
            });

        return $created;
    }
}
