<?php

namespace App\Domain;

use App\Models\BillingHandoff;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnissuedInvoiceDeletionWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function canDelete(Invoice $invoice): bool
    {
        return in_array($invoice->status, ['draft', 'ready_for_review'], true)
            && (($invoice->billing_handoff_id !== null && $invoice->service_ticket_id !== null) || $invoice->isDirect())
            && blank($invoice->issue_token)
            && blank($invoice->issued_at)
            && blank($invoice->issued_by_id)
            && blank($invoice->void_token)
            && blank($invoice->voided_at)
            && blank($invoice->voided_by_id)
            && blank($invoice->reissue_of_invoice_id)
            && blank($invoice->electronic_payment_provider)
            && blank($invoice->payment_provider_locked_at)
            && $invoice->pdf_status === 'not_requested'
            && blank($invoice->pdf_disk)
            && blank($invoice->pdf_key)
            && blank($invoice->pdf_sha256)
            && blank($invoice->pdf_failure_code)
            && ! $invoice->paymentAttempts()->exists()
            && ! $invoice->paymentTransactions()->exists()
            && ! PaymentReceipt::query()->where('invoice_id', $invoice->id)->exists()
            && ! $invoice->acknowledgments()->exists()
            && ! $invoice->serviceSnapshot()->exists()
            && ! Invoice::query()->where('reissue_of_invoice_id', $invoice->id)->exists();
    }

    public function delete(Invoice $invoice, User $actor, string $reason): ?BillingHandoff
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): ?BillingHandoff {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $this->canDelete($invoice)) {
                throw ValidationException::withMessages(['invoice' => 'This invoice is no longer eligible for deletion. Issued or financially meaningful invoices must be retained.']);
            }
            $handoff = null;
            if ($invoice->billing_handoff_id !== null) {
                $handoff = BillingHandoff::query()->lockForUpdate()->findOrFail($invoice->billing_handoff_id);
                if ((int) $handoff->current_invoice_id !== (int) $invoice->id) {
                    throw ValidationException::withMessages(['invoice' => 'Only the Billing Handoff current invoice can be deleted.']);
                }
            }

            $auditSubject = $handoff ?? Customer::query()
                ->where('organization_id', $invoice->organization_id)
                ->findOrFail($invoice->customer_id);
            $this->audit->record($invoice->organization, $actor, 'invoice.unissued_deleted', $auditSubject, [
                'deleted_invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'service_ticket_id' => $invoice->service_ticket_id,
                'billing_handoff_id' => $handoff?->id,
                'customer_id' => $invoice->customer_id,
                'total_cents' => $invoice->total_cents,
                'status' => $invoice->status,
                'deletion_reason' => $reason,
            ]);

            $handoff?->update([
                'current_invoice_id' => null,
                'status' => 'ready',
                'handed_off_by_id' => null,
                'handed_off_at' => null,
                'acknowledgment_token' => null,
            ]);
            $invoice->lines()->delete();
            $invoice->closeoutLinks()->delete();
            $invoice->delete();

            return $handoff?->fresh();
        });
    }
}
