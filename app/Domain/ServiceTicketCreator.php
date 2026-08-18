<?php

namespace App\Domain;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\ScheduleWindow;
use App\Support\ServiceTicketNumber;
use Illuminate\Support\Facades\DB;

final class ServiceTicketCreator
{
    public function __construct(
        private readonly ServiceTicketNumber $numbers,
        private readonly ScheduleWindow $windows,
        private readonly AuditRecorder $audit,
        private readonly VisitCreator $visits,
        private readonly VisitScheduler $scheduler,
    ) {}

    public function create(
        Organization $organization,
        User $actor,
        array $data,
        bool $createVisit = false,
        bool $confirmConflicts = false,
    ): ServiceTicket {
        return DB::transaction(function () use ($organization, $actor, $data, $createVisit, $confirmConflicts): ServiceTicket {
            $contactId = $data['contact_id'] ?? $this->defaultContactId((int) $data['service_location_id'], (int) $data['customer_id']);
            $ticket = ServiceTicket::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $data['customer_id'],
                'service_location_id' => $data['service_location_id'],
                'contact_id' => $contactId,
                'ticket_number' => $this->numbers->next($organization),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'customer_visible_summary' => $data['customer_visible_summary'] ?? null,
                'priority' => $data['priority'],
                'source' => $data['source'],
                'purpose' => $data['purpose'],
                'billing_disposition' => $data['billing_disposition'],
                'status' => 'open',
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            $this->audit->record($organization, $actor, 'service_ticket.created', $ticket, [
                'customer_id' => $ticket->customer_id,
                'service_location_id' => $ticket->service_location_id,
                'contact_id' => $ticket->contact_id,
                'priority' => $ticket->priority,
                'source' => $ticket->source,
                'purpose' => $ticket->purpose,
                'billing_disposition' => $ticket->billing_disposition,
            ]);

            if ($createVisit) {
                $visit = $this->visits->create($ticket, [
                    'service_location_id' => $ticket->service_location_id,
                    'status' => 'planned',
                    'timezone' => $ticket->serviceLocation->timezone,
                    'created_by_id' => $actor->id,
                    'updated_by_id' => $actor->id,
                ]);
                $window = $this->windows->fromLocal($data['scheduled_start'] ?? null, $data['scheduled_end'] ?? null, $visit->timezone);
                $this->scheduler->save(
                    $visit,
                    $window,
                    $data['assignees'] ?? [],
                    isset($data['lead_membership_id']) ? (int) $data['lead_membership_id'] : null,
                    $actor,
                    $confirmConflicts,
                );
                $this->audit->record($organization, $actor, 'visit.created', $visit, ['ticket_id' => $ticket->id]);
            }

            return $ticket;
        });
    }

    private function defaultContactId(int $locationId, int $customerId): ?int
    {
        return ServiceLocation::query()->findOrFail($locationId)->primary_contact_id
            ?? Contact::query()->where('customer_id', $customerId)->where('active', true)->where('is_preferred', true)->value('id');
    }
}
