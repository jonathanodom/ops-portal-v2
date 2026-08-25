<?php

namespace App\Domain\ServiceTickets\Documents;

use App\Domain\WorkItemTimeAttribution;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicket;

final class DetailedServiceReportProjection
{
    public function __construct(private readonly ServiceTicketDocumentSupport $support, private readonly WorkItemTimeAttribution $attribution) {}

    public function build(Organization $organization, ServiceTicket $ticket, OrganizationMembership $membership): array
    {
        abort_unless($ticket->organization_id === $organization->id, 404);
        $includeCloseouts = $membership->hasCapability('closeouts.inspect');
        $includeHandoff = $membership->hasCapability('billing_handoffs.view');
        $includeInvoices = $membership->hasCapability('invoices.view');
        $relations = [
            'customer:id,display_name,legal_name,phone,email', 'serviceLocation:id,primary_contact_id,name,address_line_1,address_line_2,city,state,postal_code,timezone',
            'serviceLocation.primaryContact:id,name,role,phone,email', 'contact:id,name,role,phone,email',
            'projects' => fn ($query) => $query->select('projects.id', 'project_number', 'name', 'status')->orderBy('project_number')->limit(25),
            'files' => fn ($query) => $query->where('state', 'stored')->with('uploader:id,name')->latest()->limit(100),
            'workItems' => fn ($query) => $query->with(['discoveredVisit:id,ticket_visit_number,return_of_visit_id', 'visits:id,ticket_visit_number,return_of_visit_id', 'followUpServiceTicket:id,ticket_number'])->orderBy('id')->limit(200),
            'visits' => fn ($query) => $query->with(['serviceLocation:id,name,timezone', 'returnOfVisit:id,ticket_visit_number', 'assignments.membership.user:id,name', 'timeEntries.user:id,name', 'timeEntries.workItem:id,title', 'timeEntries.corrections:id,visit_time_entry_id,sequence', 'timeEntries.allocationSets.allocations.workItem:id,title'])->orderBy('ticket_visit_number')->limit(200),
        ];
        if ($includeCloseouts) {
            $relations['visits.closeouts'] = fn ($query) => $query->with(['submittedBy:id,name', 'returnVisit:id,ticket_visit_number', 'acknowledgmentSignature', 'parts' => fn ($parts) => $parts->whereNull('removed_at')->orderBy('id'), 'media' => fn ($media) => $media->where('state', 'stored')->orderBy('id'), 'reviews.reviewer:id,name', 'reviews.adjustments', 'reviews.tripCharge'])->orderBy('version');
        }
        if ($includeHandoff) {
            $relations[] = 'billingHandoff';
        }
        if ($includeInvoices) {
            $relations['invoices'] = fn ($query) => $query->with('paymentTransactions:id,invoice_id,type,status,amount_cents')->latest('id')->limit(25);
        }
        $ticket->load($relations);

        $visitReports = $ticket->visits->map(function ($visit) use ($includeCloseouts, $ticket): array {
            $approvedReview = $includeCloseouts ? $visit->closeouts->flatMap->reviews->where('decision', 'approved')->sortByDesc('id')->first() : null;
            $adjustments = $approvedReview?->adjustments?->where('type', 'time');
            $entries = $visit->timeEntries->sortBy('effective_started_at');

            return [
                'visit' => $visit, 'site_window' => $this->support->siteWindow($entries), 'totals' => $this->support->categoryTotals($entries),
                'entries' => $entries->map(fn ($entry) => $this->support->detailedEntry($entry, $visit->timezone, $adjustments))->all(),
                'closeouts' => $includeCloseouts ? $visit->closeouts->map(fn ($closeout) => ['model' => $closeout, 'acknowledgment' => $this->support->acknowledgment($closeout, $ticket->id)])->all() : [],
            ];
        })->all();

        return compact('ticket', 'visitReports', 'includeCloseouts', 'includeHandoff', 'includeInvoices') + [
            'generatedAt' => $this->support->generatedAt($organization), 'support' => $this->support,
            'workTimeRollup' => $this->attribution->forTicket($ticket),
            'workItems' => $ticket->workItems->map(fn ($item) => $this->support->workItem($item, true)),
        ];
    }
}
