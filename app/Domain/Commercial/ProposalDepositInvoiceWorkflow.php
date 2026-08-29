<?php

namespace App\Domain\Commercial;

use App\Domain\InvoiceCalculator;
use App\Domain\InvoiceWorkflow;
use App\Models\AcceptedPaymentMilestone;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\ProposalAcceptance;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates the first accepted payment milestone as an ordinary, tax-inclusive draft invoice. */
final class ProposalDepositInvoiceWorkflow
{
    public function __construct(
        private readonly InvoiceWorkflow $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly AuditRecorder $audit,
    ) {}

    public function createForAcceptance(ProposalAcceptance $acceptance, ?User $actor): ?Invoice
    {
        return DB::transaction(function () use ($acceptance, $actor): ?Invoice {
            $acceptance = ProposalAcceptance::query()
                ->with('publication.revision.document.opportunity')
                ->lockForUpdate()
                ->findOrFail($acceptance->id);
            $milestone = AcceptedPaymentMilestone::query()
                ->where('proposal_acceptance_id', $acceptance->id)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->first();
            if (! $milestone) {
                return null;
            }
            if ($milestone->invoice_id) {
                return Invoice::query()->findOrFail($milestone->invoice_id);
            }
            if (! $actor || $actor->status !== 'active') {
                throw ValidationException::withMessages(['proposal' => 'An active Opportunity owner is required before the deposit invoice can be created.']);
            }

            /** @var Opportunity $opportunity */
            $opportunity = $acceptance->publication->revision->document->opportunity;
            if (! $opportunity->service_location_id) {
                throw ValidationException::withMessages(['proposal' => 'An active service location is required before the deposit invoice can be created.']);
            }
            $invoice = $this->invoices->createDirect(
                $opportunity->organization,
                (int) $opportunity->customer_id,
                (int) $opportunity->service_location_id,
                $opportunity->primary_contact_id ? (int) $opportunity->primary_contact_id : null,
                $actor,
                (string) Str::uuid(),
            );
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            // Milestones allocate the signed, tax-inclusive contract total; tax must not be added again.
            $invoice->update(['tax_rate_basis_points' => 0, 'internal_note' => 'Commercial accepted payment milestone '.$milestone->id]);
            $allocation = $this->allocation($acceptance, $milestone);
            foreach ($allocation['components'] as $index => $component) {
                if ($component['amount_cents'] === 0) {
                    continue;
                }
                $invoice->lines()->create([
                    'organization_id' => $invoice->organization_id,
                    'line_type' => 'service',
                    'description' => 'Deposit — '.($component['taxable'] ? 'taxable accepted scope' : 'non-taxable accepted scope'),
                    'quantity_millis' => 1000,
                    'unit' => 'deposit',
                    'unit_price_cents' => $component['amount_cents'],
                    'included' => true,
                    'billing_treatment' => 'billable',
                    'taxable' => false,
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
            $this->calculator->recalculate($invoice);
            if ((int) $invoice->fresh()->total_cents !== (int) $milestone->allocated_cents) {
                throw ValidationException::withMessages(['payment_schedule' => 'The accepted deposit allocation could not be reconciled.']);
            }
            $milestone->update(['invoice_id' => $invoice->id, 'allocation_snapshot' => $allocation]);
            $this->audit->record($invoice->organization, $actor, 'commercial.deposit_invoice_created', $invoice, [
                'invoice_id' => $invoice->id,
                'acceptance_id' => $acceptance->id,
                'accepted_payment_milestone_id' => $milestone->id,
                'allocated_cents' => (int) $milestone->allocated_cents,
            ]);

            return $invoice->fresh(['lines']);
        });
    }

    /** @return array{accepted_total_cents:int,allocated_cents:int,components:array<int,array{taxable:bool,amount_cents:int}>} */
    private function allocation(ProposalAcceptance $acceptance, AcceptedPaymentMilestone $milestone): array
    {
        $snapshotLines = $acceptance->accepted_snapshot['lines'] ?? [];
        $buckets = [true => 0, false => 0];
        foreach ($snapshotLines as $line) {
            if (! ($line['included'] ?? true)) {
                continue;
            }
            $buckets[(bool) ($line['taxable'] ?? false)] += (int) ($line['total_cents'] ?? 0);
        }
        $total = (int) $acceptance->total_cents;
        if ($total <= 0 || array_sum($buckets) !== $total) {
            // A legacy snapshot without detailed lines remains payable, but is safely non-taxable on the deposit invoice.
            $buckets = [true => 0, false => $total];
        }
        $taxable = intdiv((int) $milestone->allocated_cents * $buckets[true], $total);

        return [
            'accepted_total_cents' => $total,
            'allocated_cents' => (int) $milestone->allocated_cents,
            'components' => [
                ['taxable' => true, 'amount_cents' => $taxable],
                ['taxable' => false, 'amount_cents' => (int) $milestone->allocated_cents - $taxable],
            ],
        ];
    }
}
