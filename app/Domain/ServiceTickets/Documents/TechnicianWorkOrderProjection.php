<?php

namespace App\Domain\ServiceTickets\Documents;

use App\Models\Organization;
use App\Models\ServiceTicket;

final class TechnicianWorkOrderProjection
{
    public function __construct(private readonly ServiceTicketDocumentSupport $support) {}

    public function build(Organization $organization, ServiceTicket $ticket): array
    {
        abort_unless($ticket->organization_id === $organization->id, 404);
        $ticket->load([
            'customer:id,organization_id,display_name,legal_name,phone,email',
            'serviceLocation:id,organization_id,customer_id,primary_contact_id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
            'serviceLocation.primaryContact:id,organization_id,customer_id,name,role,phone,email',
            'contact:id,organization_id,customer_id,name,role,phone,email',
            'workItems' => fn ($query) => $query->with(['discoveredVisit:id,ticket_visit_number,return_of_visit_id', 'visits:id,ticket_visit_number,return_of_visit_id', 'followUpServiceTicket:id,ticket_number'])->orderBy('id')->limit(200),
            'visits' => fn ($query) => $query->with(['serviceLocation:id,name,timezone', 'returnOfVisit:id,ticket_visit_number', 'assignments.membership.user:id,name', 'timeEntries:id,visit_id,user_id,category,started_at,ended_at,corrected_started_at,corrected_ended_at', 'currentCloseout' => fn ($closeout) => $closeout->select('id', 'visit_id', 'status', 'representative_name', 'representative_role', 'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail')->with(['parts' => fn ($parts) => $parts->whereNull('removed_at')->orderBy('id'), 'acknowledgmentSignature'])])->orderBy('ticket_visit_number')->limit(200),
        ]);

        return ['ticket' => $ticket, 'generatedAt' => $this->support->generatedAt($organization), 'support' => $this->support];
    }
}
