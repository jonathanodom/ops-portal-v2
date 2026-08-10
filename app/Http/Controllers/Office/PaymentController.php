<?php

namespace App\Http\Controllers\Office;

use App\Domain\PaymentWorkflow;
use App\Http\Controllers\Controller;
use App\Jobs\RenderPaymentReceiptPdf;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function provider(Request $request, Invoice $invoice, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.collect');
        $data = $request->validate(['preferred_payment_provider' => ['required', Rule::in(['square', 'stripe'])]]);
        $workflow->setPreferredProvider($invoice, $request->user(), $data['preferred_payment_provider']);

        return back()->with('status', 'Electronic payment provider updated.');
    }

    public function checkout(Request $request, Invoice $invoice, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.collect');
        $data = $request->validate(['amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'idempotency_key' => ['required', 'uuid']]);
        $result = $workflow->createCheckout($invoice, $request->user(), $this->cents($data['amount']), $data['idempotency_key']);

        return redirect()->away($result['attempt']->hosted_url);
    }

    public function expire(Request $request, Invoice $invoice, PaymentAttempt $attempt, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.manage_links');
        $attempt = $this->attempt($invoice, $attempt);
        $workflow->expire($attempt, $request->user());

        return back()->with('status', 'Hosted checkout expired.');
    }

    public function reconcile(Request $request, Invoice $invoice, PaymentAttempt $attempt, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.collect');
        $attempt = $this->attempt($invoice, $attempt);
        $workflow->reconcile($attempt, $request->user());

        return back()->with('status', 'Payment status reconciled with the provider.');
    }

    public function manual(Request $request, Invoice $invoice, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.record_manual');
        $data = $request->validate(['method' => ['required', Rule::in(['cash', 'check'])], 'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'received_at' => ['required', 'date'], 'reference' => ['nullable', 'string', 'max:120'], 'idempotency_key' => ['required', 'uuid']]);
        $receivedAt = Carbon::parse($data['received_at'], $invoice->organization->timezone)->utc();
        $workflow->recordManual($invoice, $request->user(), $data['method'], $this->cents($data['amount']), $receivedAt, $data['reference'] ?? null, $data['idempotency_key']);

        return back()->with('status', ucfirst($data['method']).' payment recorded.');
    }

    public function refund(Request $request, Invoice $invoice, PaymentTransaction $transaction, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.refund');
        $transaction = $this->transaction($invoice, $transaction);
        $data = $request->validate(['amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'uuid']]);
        if (in_array($transaction->method, ['cash', 'check'], true)) {
            $workflow->reverseManual($transaction, $request->user(), $this->cents($data['amount']), $data['reason'], $data['idempotency_key']);
        } else {
            $workflow->refund($transaction, $request->user(), $this->cents($data['amount']), $data['reason'], $data['idempotency_key']);
        }

        return back()->with('status', in_array($transaction->method, ['cash', 'check'], true) ? 'Manual reversal recorded.' : 'Refund requested.');
    }

    public function receiptLink(Request $request, Invoice $invoice, PaymentReceipt $receipt, PaymentWorkflow $workflow): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.manage_links');
        $receipt = PaymentReceipt::query()->where('invoice_id', $invoice->id)->findOrFail($receipt->id);
        $result = $workflow->rotateReceiptToken($receipt, $request->user());

        return back()->with('status', 'Receipt link created.')->with('receipt_url', route('payments.receipts.show', ['receipt' => $receipt, 'token' => $result['token']]));
    }

    public function retryReceipt(Request $request, Invoice $invoice, PaymentReceipt $receipt): RedirectResponse
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.manage_links');
        $receipt = PaymentReceipt::query()->where('invoice_id', $invoice->id)->findOrFail($receipt->id);
        $receipt->update(['pdf_status' => 'pending', 'pdf_failure_code' => null]);
        RenderPaymentReceiptPdf::dispatch($receipt->id);

        return back()->with('status', 'Receipt PDF queued again.');
    }

    public function qr(Request $request, Invoice $invoice, PaymentAttempt $attempt): Response
    {
        $invoice = $this->invoice($request, $invoice);
        $this->capability($request, 'payments.collect');
        $attempt = $this->attempt($invoice, $attempt);
        abort_unless($attempt->hosted_url, 404);
        $svg = (new SvgWriter)->write(new QrCode(data: $attempt->hosted_url, size: 360, margin: 10))->getString();

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function invoice(Request $request, Invoice $invoice): Invoice
    {
        return Invoice::query()->forOrganization($request->attributes->get('organization')->id)->with('organization')->findOrFail($invoice->id);
    }

    private function attempt(Invoice $invoice, PaymentAttempt $attempt): PaymentAttempt
    {
        return PaymentAttempt::query()->where('invoice_id', $invoice->id)->with(['configuration', 'invoice.organization'])->findOrFail($attempt->id);
    }

    private function transaction(Invoice $invoice, PaymentTransaction $transaction): PaymentTransaction
    {
        return PaymentTransaction::query()->where('invoice_id', $invoice->id)->with('invoice.organization')->findOrFail($transaction->id);
    }

    private function capability(Request $request, string $capability): void
    {
        abort_unless($request->attributes->get('membership')->hasCapability($capability), 403);
    }

    private function cents(string $amount): int
    {
        [$whole,$decimal] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }
}
