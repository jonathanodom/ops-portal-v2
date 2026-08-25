<?php

namespace App\Domain\ServiceTickets\Documents;

use App\Models\Organization;
use App\Models\ServiceTicket;

final class CompletionSummaryProjection
{
    public function __construct(private readonly ServiceTicketDocumentSupport $support) {}

    public function build(Organization $organization, ServiceTicket $ticket): array
    {
        abort_unless($ticket->organization_id === $organization->id, 404);
        $ticket->load([
            'customer:id,display_name', 'serviceLocation:id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
            'workItems' => fn ($query) => $query->with(['discoveredVisit:id,ticket_visit_number,return_of_visit_id', 'visits:id,ticket_visit_number,return_of_visit_id', 'followUpServiceTicket:id,ticket_number'])->orderBy('id')->limit(200),
            'visits' => fn ($query) => $query->with(['assignments.membership.user:id,name', 'timeEntries:id,visit_id,user_id,category,started_at,ended_at,corrected_started_at,corrected_ended_at', 'currentCloseout' => fn ($closeout) => $closeout->select('id', 'visit_id', 'status', 'outcome', 'work_performed', 'recommendations', 'representative_name', 'representative_role', 'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail')->with('acknowledgmentSignature')])->orderBy('ticket_visit_number')->limit(200),
        ]);

        $visits = $ticket->visits->map(function ($visit) use ($ticket): array {
            $window = $this->support->siteWindow($visit->timeEntries);
            $closeout = $visit->currentCloseout;

            return [
                'label' => $visit->displayLabel(), 'status' => $this->support->visitState($visit), 'timezone' => $visit->timezone,
                'technicians' => $visit->assignments->map(fn ($assignment) => $assignment->membership->user->name)->unique()->values()->all(),
                'site_window' => $window, 'work_performed' => $closeout?->work_performed,
                'outcome' => $closeout?->outcome, 'recommendations' => $closeout?->recommendations,
                'acknowledgment' => $this->support->acknowledgment($closeout, $ticket->id),
            ];
        })->all();

        return ['document' => [
            'ticket_number' => $ticket->ticket_number, 'title' => $ticket->title, 'status' => $ticket->status,
            'customer' => $ticket->customer->display_name, 'location' => $ticket->serviceLocation->name,
            'address' => $ticket->serviceLocation->formattedAddress(), 'visits' => $visits,
            'work_items' => $ticket->workItems->map(fn ($item) => $this->support->workItem($item))->all(),
        ], 'generatedAt' => $this->support->generatedAt($organization), 'support' => $this->support];
    }
}
