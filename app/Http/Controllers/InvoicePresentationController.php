<?php

namespace App\Http\Controllers;

use App\Domain\InvoiceWorkflow;
use App\Models\Invoice;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePresentationController extends Controller
{
    public function show(Request $request, string $invoice): View
    {
        $invoice = $this->invoice($request, $invoice)->load(['organization', 'serviceTicket', 'serviceLocation', 'lines', 'acknowledgments', 'sellerLogoAsset', 'paymentAttempts', 'paymentTransactions.receipt']);
        Gate::authorize('present', $invoice);
        abort_unless($invoice->status === 'issued', 404);

        return view('invoices.present', compact('invoice'));
    }

    public function brand(Request $request, string $invoice): StreamedResponse
    {
        $invoice = $this->invoice($request, $invoice)->load('sellerLogoAsset');
        Gate::authorize('present', $invoice);
        abort_unless($invoice->status === 'issued' && $invoice->sellerLogoAsset, 404);

        $asset = $invoice->sellerLogoAsset;
        abort_unless(Storage::disk($asset->storage_disk)->exists($asset->storage_key), 404);

        return Storage::disk($asset->storage_disk)->response($asset->storage_key, null, [
            'Content-Type' => $asset->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function acknowledge(Request $request, string $invoice, InvoiceWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        Gate::authorize('present', $invoice);
        $data = $request->validate(['contact_name' => ['required', 'string', 'max:255'], 'confirmed' => ['accepted'], 'acknowledgment_token' => ['required', 'uuid']]);
        $workflow->acknowledge($invoice, $request->user(), $data['contact_name'], $data['acknowledgment_token']);

        return back()->with('status', 'Invoice acknowledgment recorded.');
    }

    private function invoice(Request $request, string $id): Invoice
    {
        $organization = $request->attributes->get('organization');
        $invoice = Invoice::query()->forOrganization($organization->id)->find($id);
        if (! $invoice && Invoice::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'invoice', 'record_id' => (int) $id]);
        }

        return $invoice ?? abort(404);
    }
}
