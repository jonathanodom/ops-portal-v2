<?php

namespace App\Domain\ServiceTickets\Documents;

use App\Models\Organization;
use App\Models\ServiceTicket;

final class CustomerServiceRecordProjection
{
    public function __construct(private readonly ServiceTicketDocumentSupport $support) {}

    public function build(Organization $organization, ServiceTicket $ticket): array
    {
        abort_unless($ticket->organization_id === $organization->id, 404);
        $ticket->load([
            'customer:id,display_name,legal_name', 'serviceLocation:id,primary_contact_id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
            'serviceLocation.primaryContact:id,name,role,phone,email', 'contact:id,name,role,phone,email',
            'workItems' => fn ($query) => $query->with(['discoveredVisit:id,ticket_visit_number,return_of_visit_id', 'visits:id,ticket_visit_number,return_of_visit_id', 'followUpServiceTicket:id,ticket_number'])->orderBy('id')->limit(200),
            'visits' => fn ($query) => $query->with(['returnOfVisit:id,ticket_visit_number', 'assignments.membership.user:id,name', 'timeEntries:id,visit_id,user_id,category,started_at,ended_at,corrected_started_at,corrected_ended_at', 'currentCloseout' => fn ($closeout) => $closeout->select('id', 'visit_id', 'status', 'outcome', 'work_performed', 'recommendations', 'representative_name', 'representative_role', 'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail')->with(['acknowledgmentSignature', 'parts' => fn ($parts) => $parts->whereNull('removed_at')->select('id', 'closeout_id', 'description', 'quantity', 'unit', 'billing_treatment')->orderBy('id')])])->orderBy('ticket_visit_number')->limit(200),
        ]);
        $contact = $ticket->contact ?: $ticket->serviceLocation->primaryContact;

        return ['document' => [
            'ticket' => ['number' => $ticket->ticket_number, 'title' => $ticket->title, 'status' => $ticket->status, 'scope' => $ticket->description, 'summary' => $ticket->customer_visible_summary],
            'customer' => ['name' => $ticket->customer->display_name, 'legal_name' => $ticket->customer->legal_name],
            'site' => ['name' => $ticket->serviceLocation->name, 'address' => $ticket->serviceLocation->formattedAddress(), 'timezone' => $ticket->serviceLocation->timezone],
            'contact' => $contact ? ['name' => $contact->name, 'role' => $contact->role, 'phone' => $contact->phone, 'email' => $contact->email] : null,
            'work_items' => $ticket->workItems->map(fn ($item) => $this->support->workItem($item))->all(),
            'visits' => $ticket->visits->map(function ($visit) use ($ticket): array {
                $closeout = $visit->currentCloseout;

                return [
                    'label' => $visit->displayLabel(), 'status' => $this->support->visitState($visit), 'return_of' => $visit->returnOfVisit?->displayNumber(),
                    'timezone' => $visit->timezone, 'site_window' => $this->support->siteWindow($visit->timeEntries),
                    'technicians' => $visit->assignments->map(fn ($assignment) => $assignment->membership->user->name)->unique()->values()->all(),
                    'work_performed' => $closeout?->work_performed, 'recommendations' => $closeout?->recommendations, 'outcome' => $closeout?->outcome,
                    'parts' => $closeout?->parts->map(fn ($part) => ['description' => $part->description, 'quantity' => $part->quantity, 'unit' => $part->unit])->all() ?? [],
                    'acknowledgment' => $this->support->acknowledgment($closeout, $ticket->id),
                ];
            })->all(),
        ], 'generatedAt' => $this->support->generatedAt($organization), 'support' => $this->support];
    }
}
