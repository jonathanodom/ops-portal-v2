<?php

namespace App\Http\Controllers\Office;

use App\Domain\InvoiceWorkflow;
use App\Http\Controllers\Controller;
use App\Models\BillingHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingHandoffController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->attributes->get('membership')->hasCapability('invoices.view')) {
            return redirect()->route('office.invoices.index', ['workspace' => 'ready_to_invoice']);
        }
        $organization = $request->attributes->get('organization');
        $handoffs = BillingHandoff::query()->forOrganization($organization->id)
            ->with(['serviceTicket.customer', 'serviceTicket.serviceLocation', 'visit', 'closeout', 'handedOffBy', 'currentInvoice.paymentTransactions'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()->paginate(20)->withQueryString();

        return view('office.billing-handoffs.index', compact('handoffs'));
    }

    public function createInvoice(Request $request, string $handoff, InvoiceWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $handoff = BillingHandoff::query()->forOrganization($organization->id)->findOrFail($handoff);
        $data = $request->validate(['creation_token' => ['required', 'uuid']]);
        $invoice = $workflow->createFromHandoff($handoff, $request->user(), $data['creation_token']);

        return redirect()->route('office.invoices.show', $invoice)->with('status', 'Invoice draft created.');
    }
}
