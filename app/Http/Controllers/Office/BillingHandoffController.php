<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\BillingHandoff;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BillingHandoffController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $handoffs = BillingHandoff::query()->forOrganization($organization->id)
            ->with(['serviceTicket.customer', 'visit', 'closeout.parts', 'closeout.reviews.adjustments', 'handedOffBy'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()->paginate(20)->withQueryString();

        return view('office.billing-handoffs.index', compact('handoffs'));
    }

    public function acknowledge(Request $request, string $handoff, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $handoff = BillingHandoff::query()->forOrganization($organization->id)->findOrFail($handoff);
        $data = $request->validate(['acknowledgment_token' => ['required', 'uuid']]);
        DB::transaction(function () use ($handoff, $request, $organization, $audit, $data): void {
            $handoff = BillingHandoff::query()->lockForUpdate()->findOrFail($handoff->id);
            if ($handoff->status === 'handed_off') {
                return;
            }
            $handoff->update(['status' => 'handed_off', 'handed_off_by_id' => $request->user()->id, 'handed_off_at' => now(), 'acknowledgment_token' => $data['acknowledgment_token']]);
            $audit->record($organization, $request->user(), 'billing_handoff.acknowledged', $handoff, ['ticket_id' => $handoff->service_ticket_id, 'from' => 'ready', 'to' => 'handed_off']);
        });

        return back()->with('status', 'Billing handoff acknowledged.');
    }
}
