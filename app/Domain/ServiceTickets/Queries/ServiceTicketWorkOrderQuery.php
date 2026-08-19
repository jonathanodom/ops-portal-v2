<?php

namespace App\Domain\ServiceTickets\Queries;

use App\Models\Organization;
use App\Models\ServiceTicket;
use Carbon\CarbonImmutable;

final class ServiceTicketWorkOrderQuery
{
    public function build(Organization $organization, ServiceTicket $ticket, bool $includeCloseoutEvidence): array
    {
        abort_unless($ticket->organization_id === $organization->id, 404);

        $ticket->load([
            'customer:id,organization_id,display_name,legal_name,phone,email',
            'serviceLocation:id,organization_id,customer_id,primary_contact_id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
            'serviceLocation.primaryContact:id,organization_id,customer_id,name,role,phone,email',
            'contact:id,organization_id,customer_id,name,role,phone,email',
            'projects' => fn ($query) => $query->select('projects.id', 'projects.project_number', 'projects.name', 'projects.status')->orderBy('projects.project_number')->limit(25),
            'files' => fn ($query) => $query->where('state', 'stored')->with('uploader:id,name')->latest()->limit(100),
            'visits' => fn ($query) => $query->with([
                'serviceLocation:id,name,timezone',
                'returnOfVisit:id,ticket_visit_number',
                'assignments.membership.user:id,name',
                'timeEntries:id,visit_id,category,started_at,ended_at',
                'currentCloseout' => function ($closeouts) use ($includeCloseoutEvidence): void {
                    if (! $includeCloseoutEvidence) {
                        $closeouts->select('id', 'visit_id', 'status');

                        return;
                    }

                    $closeouts->with([
                        'parts' => fn ($parts) => $parts->whereNull('removed_at')->orderBy('id'),
                        'media' => fn ($media) => $media->where('state', 'stored')->orderBy('category')->orderBy('id'),
                    ]);
                },
            ])->orderBy('ticket_visit_number')->limit(200),
        ]);

        return [
            'ticket' => $ticket,
            'generatedAt' => CarbonImmutable::now($organization->timezone),
            'includeCloseoutEvidence' => $includeCloseoutEvidence,
        ];
    }
}
