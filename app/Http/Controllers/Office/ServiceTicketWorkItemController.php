<?php

namespace App\Http\Controllers\Office;

use App\Domain\ServiceTicketWorkItemWorkflow;
use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class ServiceTicketWorkItemController extends Controller
{
    public function store(Request $request, string $serviceTicket, ServiceTicketWorkItemWorkflow $workflow): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $workflow->createFromOffice($ticket, $request->user(), $this->itemData($request));

        return back()->with('status', 'Work Item added.');
    }

    public function update(Request $request, string $serviceTicket, string $workItem, ServiceTicketWorkItemWorkflow $workflow): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $item = $this->item($ticket, $workItem);
        $workflow->updateFromOffice($item, $request->user(), $this->itemData($request));

        return back()->with('status', 'Work Item updated.');
    }

    public function transfer(Request $request, string $serviceTicket, string $workItem, ServiceTicketWorkItemWorkflow $workflow): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'priority' => ['required', Rule::in(array_keys(config('service_tickets.priorities')))],
            'purpose' => ['required', Rule::in(array_keys(config('service_tickets.purposes')))],
            'billing_disposition' => ['required', Rule::in(array_keys(config('service_tickets.billing_dispositions')))],
        ]);
        $followUp = $workflow->transfer($this->item($ticket, $workItem), $request->attributes->get('organization'), $request->user(), $data);

        return redirect()->route('office.service-tickets.show', $followUp)->with('status', 'Follow-up Service Ticket created from Work Item.');
    }

    private function itemData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'], 'detail' => ['nullable', 'string', 'max:10000'],
            'work_note' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['open', 'completed', 'needs_follow_up', 'canceled'])],
        ]);
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        $organization = $request->attributes->get('organization');
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->find($id);
        if (! $ticket) {
            if (ServiceTicket::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'service_ticket', 'record_id' => (int) $id]);
            }
            abort(404);
        }

        return $ticket;
    }

    private function item(ServiceTicket $ticket, string $id): ServiceTicketWorkItem
    {
        return ServiceTicketWorkItem::query()->forOrganization($ticket->organization_id)->where('service_ticket_id', $ticket->id)->findOrFail($id);
    }
}
