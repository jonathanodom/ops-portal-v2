<?php

namespace App\Domain;

use App\Jobs\RenderInvoicePdf;
use App\Models\BillingHandoff;
use App\Models\BillingLaborRate;
use App\Models\Closeout;
use App\Models\Invoice;
use App\Models\InvoiceAcknowledgment;
use App\Models\InvoiceLine;
use App\Models\OrganizationBillingSetting;
use App\Models\User;
use App\Models\VisitPartProposal;
use App\Support\AuditRecorder;
use App\Support\InvoiceNumber;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceWorkflow
{
    public function __construct(
        private readonly InvoiceNumber $numbers,
        private readonly InvoiceCalculator $calculator,
        private readonly AuditRecorder $audit,
    ) {}

    public function createFromHandoff(BillingHandoff $handoff, User $actor, string $token): Invoice
    {
        if ($existing = Invoice::query()->where('creation_token', $token)->first()) {
            abort_unless((int) $existing->billing_handoff_id === (int) $handoff->id, 422);

            return $existing;
        }

        return DB::transaction(function () use ($handoff, $actor, $token): Invoice {
            $handoff = BillingHandoff::query()->lockForUpdate()->findOrFail($handoff->id);
            if ($handoff->current_invoice_id) {
                return Invoice::query()->findOrFail($handoff->current_invoice_id);
            }
            if ($handoff->status !== 'ready') {
                throw ValidationException::withMessages(['handoff' => 'This handoff is no longer ready for invoice creation.']);
            }
            $ticket = $handoff->serviceTicket()->with(['organization', 'customer.preferredContact', 'serviceLocation', 'contact'])->lockForUpdate()->firstOrFail();
            $closeouts = Closeout::query()
                ->where('organization_id', $ticket->organization_id)
                ->where('status', 'submitted')
                ->whereHas('visit', fn ($query) => $query->where('service_ticket_id', $ticket->id)->whereNull('deleted_at')->where('status', 'approved'))
                ->whereHas('reviews', fn ($query) => $query->where('decision', 'approved'))
                ->with(['visit.timeEntries', 'reviews.adjustments', 'parts'])
                ->orderBy('visit_id')->orderBy('version')->get()
                ->filter(fn (Closeout $closeout) => $closeout->reviews->contains('decision', 'approved'))
                ->values();
            if ($closeouts->isEmpty()) {
                throw ValidationException::withMessages(['handoff' => 'No eligible approved closeout versions were found.']);
            }
            $settings = OrganizationBillingSetting::query()->firstOrCreate(['organization_id' => $ticket->organization_id], ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
            $defaultRate = BillingLaborRate::query()->forOrganization($ticket->organization_id)->where('active', true)->where('is_default', true)->first();
            $hasLabor = $closeouts->contains(fn (Closeout $closeout) => $this->effectiveMinutes($closeout) > 0);
            if ($hasLabor && ! $defaultRate) {
                throw ValidationException::withMessages(['labor_rate' => 'Configure one active default labor rate before creating this invoice.']);
            }
            $contact = $ticket->contact?->active ? $ticket->contact : $ticket->customer->preferredContact;
            $invoice = Invoice::query()->create([
                'organization_id' => $ticket->organization_id,
                'customer_id' => $ticket->customer_id,
                'service_location_id' => $ticket->service_location_id,
                'service_ticket_id' => $ticket->id,
                'billing_handoff_id' => $handoff->id,
                'generation' => 1,
                'invoice_number' => $this->numbers->next($ticket->organization),
                'status' => 'draft',
                'currency' => $settings->default_currency ?? 'USD',
                'payment_terms' => $settings->default_payment_terms ?? 'due_on_receipt',
                'billing_name' => $ticket->customer->display_name,
                'billing_legal_name' => $ticket->customer->legal_name,
                'billing_contact_name' => $contact?->name,
                'billing_email' => $contact?->email ?? $ticket->customer->email,
                'billing_phone' => $contact?->phone ?? $ticket->customer->phone,
                'billing_address_line_1' => $ticket->serviceLocation->address_line_1,
                'billing_address_line_2' => $ticket->serviceLocation->address_line_2,
                'billing_city' => $ticket->serviceLocation->city,
                'billing_state' => $ticket->serviceLocation->state,
                'billing_postal_code' => $ticket->serviceLocation->postal_code,
                ...$this->sellerSnapshot($settings),
                'tax_rate_basis_points' => $settings->default_tax_rate_basis_points ?? 0,
                'creation_token' => $token,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $sort = 10;
            foreach ($closeouts as $closeout) {
                $review = $closeout->reviews->firstWhere('decision', 'approved');
                $invoice->closeoutLinks()->create(['organization_id' => $ticket->organization_id, 'visit_id' => $closeout->visit_id, 'closeout_id' => $closeout->id, 'closeout_review_id' => $review->id]);
                $minutes = $this->effectiveMinutes($closeout);
                if ($minutes > 0) {
                    $rounded = (int) (ceil($minutes / 15) * 15);
                    $invoice->lines()->create([
                        'organization_id' => $ticket->organization_id,
                        'line_type' => 'labor',
                        'description' => "Visit #{$closeout->visit_id} — {$ticket->ticket_number}: {$ticket->title}",
                        'quantity_millis' => intdiv($rounded * 1000, 60),
                        'unit' => 'hour',
                        'unit_price_cents' => $defaultRate?->hourly_rate_cents,
                        'labor_rate_id' => $defaultRate?->id,
                        'source_visit_id' => $closeout->visit_id,
                        'source_closeout_id' => $closeout->id,
                        'source_review_id' => $review->id,
                        'sort_order' => $sort++,
                    ]);
                }
                $adjustments = $review->adjustments->where('type', 'part')->keyBy('visit_part_proposal_id');
                foreach ($closeout->parts->whereNull('removed_at') as $part) {
                    $adjustment = $adjustments->get($part->id);
                    if ($adjustment?->excluded) {
                        continue;
                    }
                    $treatment = $adjustment?->approved_billing_treatment ?? $part->billing_treatment;
                    $quantity = (string) ($adjustment?->approved_quantity ?? $part->quantity);
                    if ($treatment !== 'billable') {
                        continue;
                    }
                    $invoice->lines()->create([
                        'organization_id' => $ticket->organization_id,
                        'line_type' => 'part',
                        'description' => $part->description,
                        'quantity_millis' => $this->quantityToMillis($quantity),
                        'unit' => $adjustment?->approved_unit ?? $part->unit,
                        'unit_price_cents' => null,
                        'billing_treatment' => $treatment,
                        'taxable' => true,
                        'source_visit_id' => $closeout->visit_id,
                        'source_closeout_id' => $closeout->id,
                        'source_review_id' => $review->id,
                        'source_part_proposal_id' => $part->id,
                        'sort_order' => $sort++,
                    ]);
                }
            }
            $this->calculator->recalculate($invoice);
            $handoff->update(['current_invoice_id' => $invoice->id, 'status' => 'handed_off', 'handed_off_by_id' => $actor->id, 'handed_off_at' => now(), 'acknowledgment_token' => $token]);
            $this->audit->record($ticket->organization, $actor, 'invoice.created', $invoice, ['ticket_id' => $ticket->id, 'handoff_id' => $handoff->id, 'closeout_ids' => $closeouts->pluck('id')->all()]);

            return $invoice->load(['lines', 'closeoutLinks']);
        });
    }

    /** @param array<string, mixed> $values */
    public function update(Invoice $invoice, User $actor, array $values): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $values): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $invoice->update(Arr::only($values, ['payment_terms', 'due_on', 'billing_name', 'billing_legal_name', 'billing_contact_name', 'billing_email', 'billing_phone', 'billing_address_line_1', 'billing_address_line_2', 'billing_city', 'billing_state', 'billing_postal_code', 'customer_note', 'internal_note', 'discount_type', 'discount_value', 'discount_reason', 'tax_rate_basis_points', 'tax_override_reason']) + ['updated_by_id' => $actor->id]);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.updated', $invoice, ['invoice_id' => $invoice->id, 'changed_fields' => array_keys($values)]);

            return $invoice;
        });
    }

    /** @param array<string, mixed> $values */
    public function addLine(Invoice $invoice, User $actor, array $values): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $actor, $values): InvoiceLine {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $line = $invoice->lines()->create($values + ['organization_id' => $invoice->organization_id, 'sort_order' => ((int) $invoice->lines()->max('sort_order')) + 10]);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.line_created', $line, ['invoice_id' => $invoice->id, 'line_type' => $line->line_type, 'changed_fields' => array_keys($values)]);

            return $line;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateLine(Invoice $invoice, InvoiceLine $line, User $actor, array $values): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $line, $actor, $values): InvoiceLine {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $line = InvoiceLine::query()->where('invoice_id', $invoice->id)->lockForUpdate()->findOrFail($line->id);
            $line->update($values);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.line_updated', $line, ['invoice_id' => $invoice->id, 'line_type' => $line->line_type, 'changed_fields' => array_keys($values)]);

            return $line;
        });
    }

    public function includeProposal(Invoice $invoice, VisitPartProposal $part, User $actor, string $reason): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $part, $actor, $reason): InvoiceLine {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $link = $invoice->closeoutLinks()->where('closeout_id', $part->closeout_id)->firstOrFail();
            $part = VisitPartProposal::query()->where('organization_id', $invoice->organization_id)->where('closeout_id', $link->closeout_id)->whereNull('removed_at')->findOrFail($part->id);
            $review = $link->review()->with('adjustments')->firstOrFail();
            $adjustment = $review->adjustments->firstWhere('visit_part_proposal_id', $part->id);
            if ($adjustment?->excluded) {
                throw ValidationException::withMessages(['part' => 'An excluded reviewed proposal cannot be invoiced.']);
            }
            if ($existing = $invoice->lines()->where('source_part_proposal_id', $part->id)->first()) {
                return $existing;
            }
            $line = $invoice->lines()->create([
                'organization_id' => $invoice->organization_id, 'line_type' => 'part', 'description' => $part->description,
                'quantity_millis' => $this->quantityToMillis((string) ($adjustment?->approved_quantity ?? $part->quantity)),
                'unit' => $adjustment?->approved_unit ?? $part->unit, 'unit_price_cents' => null, 'included' => true,
                'billing_treatment' => 'billable', 'taxable' => true, 'source_visit_id' => $part->visit_id,
                'source_closeout_id' => $part->closeout_id, 'source_review_id' => $review->id,
                'source_part_proposal_id' => $part->id, 'sort_order' => ((int) $invoice->lines()->max('sort_order')) + 10,
                'override_reason' => $reason,
            ]);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.proposal_treatment_overridden', $line, [
                'invoice_id' => $invoice->id, 'proposal_id' => $part->id,
                'from' => $adjustment?->approved_billing_treatment ?? $part->billing_treatment,
                'to' => 'billable', 'changed_fields' => ['billing_treatment'],
            ]);

            return $line;
        });
    }

    public function markReady(Invoice $invoice, User $actor): Invoice
    {
        $this->assertEditable($invoice);
        $this->validateForIssue($invoice);
        $invoice->update(['status' => 'ready_for_review', 'updated_by_id' => $actor->id]);
        $this->audit->record($invoice->organization, $actor, 'invoice.ready_for_review', $invoice, ['invoice_id' => $invoice->id, 'from' => 'draft', 'to' => 'ready_for_review']);

        return $invoice;
    }

    public function issue(Invoice $invoice, User $actor, string $token): Invoice
    {
        if ($existing = Invoice::query()->where('issue_token', $token)->first()) {
            abort_unless((int) $existing->id === (int) $invoice->id, 422);

            return $existing;
        }
        $issued = DB::transaction(function () use ($invoice, $actor, $token): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'issued') {
                return $invoice;
            }
            if ($invoice->status !== 'ready_for_review') {
                throw ValidationException::withMessages(['status' => 'Move the invoice to ready for review before issuing it.']);
            }
            $settings = OrganizationBillingSetting::query()->where('organization_id', $invoice->organization_id)->first();
            if ($settings?->isComplete()) {
                $invoice->update($this->sellerSnapshot($settings));
            }
            $this->calculator->recalculate($invoice);
            $this->validateForIssue($invoice);
            $invoice->update([
                'status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $actor->id, 'issue_token' => $token,
                'due_on' => $invoice->payment_terms === 'due_on_receipt' ? today($invoice->organization->timezone) : $invoice->due_on,
                'pdf_status' => 'pending', 'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($invoice->organization, $actor, 'invoice.issued', $invoice, ['invoice_id' => $invoice->id, 'ticket_id' => $invoice->service_ticket_id, 'changed_fields' => ['status', 'issued_at', 'due_on', 'pdf_status']]);

            return $invoice;
        });
        RenderInvoicePdf::dispatch($issued->id)->afterCommit();

        return $issued;
    }

    public function acknowledge(Invoice $invoice, User $actor, string $name, string $token): InvoiceAcknowledgment
    {
        if ($existing = InvoiceAcknowledgment::query()->where('acknowledgment_token', $token)->first()) {
            abort_unless((int) $existing->invoice_id === (int) $invoice->id, 422);

            return $existing;
        }
        if ($invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Only an issued invoice can be acknowledged.']);
        }
        $ack = $invoice->acknowledgments()->create(['organization_id' => $invoice->organization_id, 'contact_name' => $name, 'confirmed' => true, 'presented_by_id' => $actor->id, 'acknowledged_at' => now(), 'acknowledgment_token' => $token]);
        $this->audit->record($invoice->organization, $actor, 'invoice.acknowledged', $ack, ['invoice_id' => $invoice->id, 'changed_fields' => ['confirmed', 'acknowledged_at']]);

        return $ack;
    }

    public function voidAndReissue(Invoice $invoice, User $actor, string $reason, string $token): Invoice
    {
        if ($existing = Invoice::query()->where('creation_token', $token)->first()) {
            abort_unless((int) $existing->reissue_of_invoice_id === (int) $invoice->id, 422);

            return $existing;
        }

        return DB::transaction(function () use ($invoice, $actor, $reason, $token): Invoice {
            $invoice = Invoice::query()->with(['lines', 'closeoutLinks'])->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'void') {
                return Invoice::query()->where('reissue_of_invoice_id', $invoice->id)->latest('generation')->firstOrFail();
            }
            $handoff = BillingHandoff::query()->lockForUpdate()->findOrFail($invoice->billing_handoff_id);
            abort_unless((int) $handoff->current_invoice_id === (int) $invoice->id, 409);
            $invoice->update(['status' => 'void', 'voided_at' => now(), 'voided_by_id' => $actor->id, 'void_reason' => $reason, 'void_token' => $token]);
            $copy = Arr::except($invoice->getAttributes(), ['id', 'invoice_number', 'status', 'generation', 'reissue_of_invoice_id', 'creation_token', 'issue_token', 'issued_at', 'issued_by_id', 'voided_at', 'voided_by_id', 'void_reason', 'void_token', 'pdf_status', 'pdf_disk', 'pdf_key', 'pdf_sha256', 'pdf_failure_code', 'created_at', 'updated_at']);
            $replacement = Invoice::query()->create($copy + [
                'invoice_number' => $this->numbers->next($invoice->organization), 'status' => 'draft', 'generation' => $invoice->generation + 1,
                'reissue_of_invoice_id' => $invoice->id, 'creation_token' => $token, 'pdf_status' => 'not_requested', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            foreach ($invoice->lines as $line) {
                $replacement->lines()->create(Arr::except($line->getAttributes(), ['id', 'invoice_id', 'created_at', 'updated_at']) + ['organization_id' => $invoice->organization_id]);
            }
            foreach ($invoice->closeoutLinks as $link) {
                $replacement->closeoutLinks()->create(Arr::except($link->getAttributes(), ['id', 'invoice_id', 'created_at', 'updated_at']) + ['organization_id' => $invoice->organization_id]);
            }
            $handoff->update(['current_invoice_id' => $replacement->id]);
            $this->audit->record($invoice->organization, $actor, 'invoice.voided', $invoice, ['invoice_id' => $invoice->id, 'replacement_invoice_id' => $replacement->id, 'changed_fields' => ['status', 'voided_at']]);
            $this->audit->record($invoice->organization, $actor, 'invoice.reissued', $replacement, ['invoice_id' => $replacement->id, 'reissue_of_invoice_id' => $invoice->id]);

            return $replacement;
        });
    }

    private function effectiveMinutes(Closeout $closeout): int
    {
        $review = $closeout->reviews->firstWhere('decision', 'approved');
        $adjustments = $review->adjustments->where('type', 'time')->keyBy('visit_time_entry_id');

        return (int) $closeout->visit->timeEntries->whereIn('category', ['on_site', 'other'])->sum(function ($entry) use ($adjustments): int {
            $adjustment = $adjustments->get($entry->id);
            if ($adjustment?->excluded || ! $entry->ended_at) {
                return 0;
            }

            return $adjustment?->approved_minutes ?? (int) ceil($entry->started_at->diffInSeconds($entry->ended_at) / 60);
        });
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(OrganizationBillingSetting $settings): array
    {
        return collect(['seller_name', 'seller_legal_name', 'seller_email', 'seller_phone', 'seller_address_line_1', 'seller_address_line_2', 'seller_city', 'seller_state', 'seller_postal_code'])->mapWithKeys(fn ($field) => [$field => $settings->{$field}])->all();
    }

    private function assertEditable(Invoice $invoice): void
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages(['invoice' => 'Issued and void invoices are immutable.']);
        }
    }

    private function validateForIssue(Invoice $invoice): void
    {
        $settings = OrganizationBillingSetting::query()->where('organization_id', $invoice->organization_id)->first();
        if (! $settings?->isComplete()) {
            throw ValidationException::withMessages(['billing_profile' => 'Complete the organization billing profile before issuing.']);
        }
        if (collect(['billing_name', 'billing_address_line_1', 'billing_city', 'billing_state', 'billing_postal_code'])->contains(fn (string $field): bool => blank($invoice->{$field}))) {
            throw ValidationException::withMessages(['billing_snapshot' => 'Complete the customer name and service billing address before issuing.']);
        }
        if ($invoice->lines()->where('included', true)->whereNull('unit_price_cents')->exists()) {
            throw ValidationException::withMessages(['lines' => 'Every included invoice line requires a unit price.']);
        }
        if ($invoice->payment_terms !== 'due_on_receipt' && ! $invoice->due_on) {
            throw ValidationException::withMessages(['due_on' => 'Choose a due date for custom payment terms.']);
        }
        if ($invoice->tax_rate_basis_points !== $settings->default_tax_rate_basis_points && blank($invoice->tax_override_reason)) {
            throw ValidationException::withMessages(['tax_override_reason' => 'A reason is required when overriding the default tax rate.']);
        }
        if ($invoice->discount_type && blank($invoice->discount_reason)) {
            throw ValidationException::withMessages(['discount_reason' => 'A reason is required for a discount.']);
        }
    }

    private function quantityToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }
}
