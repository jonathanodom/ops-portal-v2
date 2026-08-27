<?php

namespace App\Domain;

use App\Jobs\RenderInvoicePdf;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAcknowledgment;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Models\Visit;
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
        private readonly BillableLaborCalculator $laborCalculator,
        private readonly LaborServiceResolver $laborServices,
        private readonly CatalogLineSnapshotFactory $catalogSnapshots,
        private readonly ApprovedVisitLaborMinutes $approvedLaborMinutes,
        private readonly InvoiceServiceSnapshotFactory $serviceSnapshots,
        private readonly AuditRecorder $audit,
    ) {}

    public function createDirect(
        Organization $organization,
        int $customerId,
        int $serviceLocationId,
        ?int $contactId,
        User $actor,
        string $token,
    ): Invoice {
        if ($existing = Invoice::query()->where('creation_token', $token)->first()) {
            abort_unless(
                $existing->isDirect()
                && (int) $existing->organization_id === (int) $organization->id
                && (int) $existing->customer_id === $customerId
                && (int) $existing->service_location_id === $serviceLocationId,
                422,
            );

            return $existing;
        }

        return DB::transaction(function () use ($organization, $customerId, $serviceLocationId, $contactId, $actor, $token): Invoice {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            if ($existing = Invoice::query()->where('creation_token', $token)->first()) {
                abort_unless(
                    $existing->isDirect()
                    && (int) $existing->organization_id === (int) $organization->id
                    && (int) $existing->customer_id === $customerId
                    && (int) $existing->service_location_id === $serviceLocationId,
                    422,
                );

                return $existing;
            }
            $customer = Customer::query()
                ->forOrganization($organization->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->find($customerId);
            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Select an active customer in this organization.']);
            }
            $location = ServiceLocation::query()
                ->where('organization_id', $organization->id)
                ->where('customer_id', $customer->id)
                ->where('active', true)
                ->lockForUpdate()
                ->find($serviceLocationId);
            if (! $location) {
                throw ValidationException::withMessages(['service_location_id' => 'Select an active location belonging to this customer.']);
            }

            $contact = null;
            if ($contactId !== null) {
                $contact = Contact::query()
                    ->where('organization_id', $organization->id)
                    ->where('customer_id', $customer->id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->find($contactId);
                if (! $contact) {
                    throw ValidationException::withMessages(['contact_id' => 'Select an active billing contact belonging to this customer.']);
                }
            } else {
                $contact = Contact::query()
                    ->where('organization_id', $organization->id)
                    ->where('customer_id', $customer->id)
                    ->where('active', true)
                    ->where(function ($query) use ($location): void {
                        $query->whereKey($location->primary_contact_id)
                            ->orWhere('is_preferred', true);
                    })
                    ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$location->primary_contact_id ?? 0])
                    ->lockForUpdate()
                    ->first();
            }

            $settings = OrganizationBillingSetting::query()->firstOrCreate(
                ['organization_id' => $organization->id],
                ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt'],
            );
            $invoice = Invoice::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'service_location_id' => $location->id,
                'service_ticket_id' => null,
                'billing_handoff_id' => null,
                'generation' => 1,
                'invoice_number' => $this->numbers->next($organization),
                'status' => 'draft',
                'currency' => $settings->default_currency ?? 'USD',
                'payment_terms' => $settings->default_payment_terms ?? 'due_on_receipt',
                'billing_name' => $customer->display_name,
                'billing_legal_name' => $customer->legal_name,
                'billing_contact_name' => $contact?->name,
                'billing_email' => $contact?->email ?? $customer->email,
                'billing_phone' => $contact?->phone ?? $customer->phone,
                'billing_address_line_1' => $location->address_line_1,
                'billing_address_line_2' => $location->address_line_2,
                'billing_city' => $location->city,
                'billing_state' => $location->state,
                'billing_postal_code' => $location->postal_code,
                ...$this->sellerSnapshot($organization),
                'tax_rate_basis_points' => $settings->default_tax_rate_basis_points ?? 0,
                'creation_token' => $token,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($organization, $actor, 'invoice.direct_created', $invoice, [
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'service_location_id' => $location->id,
                'contact_id' => $contact?->id,
                'source_type' => 'direct',
            ]);

            return $invoice->load(['customer', 'serviceLocation', 'lines']);
        });
    }

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
                ->with(['visit.timeEntries', 'reviews.adjustments', 'reviews.tripCharge', 'parts'])
                ->orderBy('visit_id')->orderBy('version')->get()
                ->filter(fn (Closeout $closeout) => $closeout->reviews->contains('decision', 'approved'))
                ->values();
            if ($closeouts->isEmpty()) {
                throw ValidationException::withMessages(['handoff' => 'No eligible approved closeout versions were found.']);
            }
            $settings = OrganizationBillingSetting::query()->firstOrCreate(['organization_id' => $ticket->organization_id], ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
            $hasLabor = $closeouts->contains(fn (Closeout $closeout) => $this->approvedLaborMinutes->calculate($closeout) > 0);
            $laborService = $hasLabor ? $this->laborServices->resolve($ticket->organization_id) : null;
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
                ...$this->sellerSnapshot($ticket->organization),
                'tax_rate_basis_points' => $settings->default_tax_rate_basis_points ?? 0,
                'creation_token' => $token,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $sort = 10;
            $multipleVisits = $closeouts->pluck('visit_id')->unique()->count() > 1;
            foreach ($closeouts as $closeout) {
                $review = $closeout->reviews->firstWhere('decision', 'approved');
                $invoice->closeoutLinks()->create(['organization_id' => $ticket->organization_id, 'visit_id' => $closeout->visit_id, 'closeout_id' => $closeout->id, 'closeout_review_id' => $review->id]);
                $minutes = $this->approvedLaborMinutes->calculate($closeout);
                if ($minutes > 0) {
                    $calculation = $this->laborCalculator->calculate(
                        $minutes,
                        (int) $settings->labor_billing_increment_minutes,
                        $settings->labor_rounding_rule,
                        (int) $settings->minimum_billable_minutes,
                    );
                    $snapshot = $this->catalogSnapshots->create(
                        $ticket->organization_id,
                        'service',
                        $laborService->id,
                        $calculation['quantity_millis'],
                    );
                    $invoice->lines()->create($snapshot + [
                        'organization_id' => $ticket->organization_id,
                        'line_type' => 'labor',
                        'description' => $this->customerLaborDescription($closeout->visit, $ticket->title, $multipleVisits),
                        'quantity_millis' => $calculation['quantity_millis'],
                        'unit' => $snapshot['catalog_unit_name_snapshot'],
                        'unit_price_cents' => $snapshot['catalog_unit_price_cents'],
                        'labor_rate_id' => null,
                        'taxable' => $snapshot['catalog_taxable'],
                        'catalog_selected_by_id' => $actor->id,
                        'catalog_selected_at' => now(),
                        'source_visit_id' => $closeout->visit_id,
                        'source_closeout_id' => $closeout->id,
                        'source_review_id' => $review->id,
                        'sort_order' => $sort++,
                    ]);
                }
                if ($tripCharge = $review->tripCharge) {
                    $invoice->lines()->create([
                        'organization_id' => $ticket->organization_id,
                        'line_type' => 'travel',
                        'description' => $tripCharge->catalog_name_snapshot,
                        'quantity_millis' => 1000,
                        'unit' => $tripCharge->catalog_unit_name_snapshot,
                        'unit_price_cents' => $tripCharge->catalog_unit_price_cents,
                        'included' => true,
                        'billing_treatment' => 'billable',
                        'taxable' => $tripCharge->catalog_taxable,
                        'source_visit_id' => $closeout->visit_id,
                        'source_closeout_id' => $closeout->id,
                        'source_review_id' => $review->id,
                        'source_travel_seconds' => $tripCharge->recorded_travel_seconds,
                        'sort_order' => $sort++,
                        'catalog_item_type' => 'service',
                        'catalog_service_id' => $tripCharge->catalog_service_id,
                        'catalog_service_variant_id' => $tripCharge->catalog_service_variant_id,
                        'catalog_code_snapshot' => $tripCharge->catalog_code_snapshot,
                        'catalog_name_snapshot' => $tripCharge->catalog_name_snapshot,
                        'catalog_description_snapshot' => $tripCharge->catalog_description_snapshot,
                        'catalog_unit_code_snapshot' => $tripCharge->catalog_unit_code_snapshot,
                        'catalog_unit_name_snapshot' => $tripCharge->catalog_unit_name_snapshot,
                        'catalog_quantity_millis' => 1000,
                        'catalog_original_unit_price_cents' => $tripCharge->catalog_unit_price_cents,
                        'catalog_unit_price_cents' => $tripCharge->catalog_unit_price_cents,
                        'catalog_taxable' => $tripCharge->catalog_taxable,
                        'catalog_selected_by_id' => $tripCharge->selected_by_id,
                        'catalog_selected_at' => $tripCharge->selected_at,
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
                    $invoice->lines()->create($this->catalogSnapshotFromProposal($part) + [
                        'organization_id' => $ticket->organization_id,
                        'line_type' => $this->lineTypeForCatalog($part->catalog_item_type),
                        'description' => $part->description,
                        'quantity_millis' => $adjustment ? $this->quantityToMillis($quantity) : ($part->catalog_quantity_millis ?? $this->quantityToMillis($quantity)),
                        'unit' => $adjustment?->approved_unit ?? $part->unit,
                        'unit_price_cents' => $part->catalog_item_type ? $part->catalog_unit_price_cents : null,
                        'billing_treatment' => $treatment,
                        'taxable' => $part->catalog_item_type ? (bool) $part->catalog_taxable : true,
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
            if (array_key_exists('preferred_payment_provider', $values)) {
                $invoice->forceFill(['preferred_payment_provider' => $values['preferred_payment_provider']])->save();
            }
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

    /** @param array<string, mixed> $snapshot */
    public function addCatalogLine(Invoice $invoice, User $actor, array $snapshot): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $actor, $snapshot): InvoiceLine {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $selectedAt = now();
            $line = $invoice->lines()->create($snapshot + [
                'organization_id' => $invoice->organization_id,
                'line_type' => $this->lineTypeForCatalog($snapshot['catalog_item_type']),
                'description' => $snapshot['catalog_name_snapshot'],
                'quantity_millis' => $snapshot['catalog_quantity_millis'],
                'unit' => $snapshot['catalog_unit_name_snapshot'],
                'unit_price_cents' => $snapshot['catalog_unit_price_cents'],
                'included' => true,
                'billing_treatment' => 'billable',
                'taxable' => $snapshot['catalog_taxable'],
                'sort_order' => ((int) $invoice->lines()->max('sort_order')) + 10,
                'catalog_selected_by_id' => $actor->id,
                'catalog_selected_at' => $selectedAt,
            ]);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.catalog_line_created', $line, [
                'invoice_id' => $invoice->id,
                'invoice_line_id' => $line->id,
                'catalog_item_type' => $line->catalog_item_type,
                'catalog_source_id' => $line->catalog_service_id ?: ($line->catalog_product_id ?: $line->catalog_package_id),
                'catalog_variant_id' => $line->catalog_service_variant_id,
                'quantity_millis' => $line->quantity_millis,
            ]);

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
            $currentPrice = $line->unit_price_cents === null ? null : (int) $line->unit_price_cents;
            $nextPrice = ($values['unit_price_cents'] ?? null) === null ? null : (int) $values['unit_price_cents'];
            $priceChanged = $line->catalog_item_type && array_key_exists('unit_price_cents', $values) && $currentPrice !== $nextPrice;
            $line->update($values);
            $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.line_updated', $line, ['invoice_id' => $invoice->id, 'line_type' => $line->line_type, 'changed_fields' => array_keys($values)]);
            if ($priceChanged) {
                $this->audit->record($invoice->organization, $actor, 'invoice.catalog_price_overridden', $line, [
                    'invoice_id' => $invoice->id,
                    'invoice_line_id' => $line->id,
                    'catalog_item_type' => $line->catalog_item_type,
                    'changed_fields' => ['unit_price_cents'],
                ]);
            }

            return $line;
        });
    }

    public function removeLine(Invoice $invoice, InvoiceLine $line, User $actor, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $line, $actor, $reason): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->assertEditable($invoice);
            $line = InvoiceLine::query()
                ->where('organization_id', $invoice->organization_id)
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->findOrFail($line->id);

            $provenance = $this->lineRemovalProvenance($line);
            $hasOperationalSource = collect($provenance)
                ->except(['labor_rate_id', 'catalog_item_type', 'catalog_source_id', 'catalog_service_variant_id', 'catalog_code_snapshot'])
                ->contains(fn (mixed $value): bool => $value !== null);
            if ($hasOperationalSource && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'Explain why this approved-work charge is being removed.']);
            }

            $snapshot = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_status' => $invoice->status,
                'invoice_line_id' => $line->id,
                'line_type' => $line->line_type,
                'description' => $line->description,
                'quantity_millis' => (int) $line->quantity_millis,
                'unit' => $line->unit,
                'unit_price_cents' => $line->unit_price_cents === null ? null : (int) $line->unit_price_cents,
                'subtotal_cents' => (int) $line->subtotal_cents,
                'discount_cents' => (int) $line->discount_cents,
                'tax_cents' => (int) $line->tax_cents,
                'amount_cents' => (int) $line->total_cents,
                'source_provenance' => $provenance,
                'reason' => filled($reason) ? $reason : 'Removed while editing the invoice.',
                'changed_fields' => ['lines', 'subtotal_cents', 'discount_total_cents', 'tax_total_cents', 'total_cents'],
            ];

            $line->delete();
            $invoice = $this->calculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.line_removed', $invoice, $snapshot + [
                'recalculated_total_cents' => (int) $invoice->total_cents,
            ]);

            return $invoice;
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
            $line = $invoice->lines()->create($this->catalogSnapshotFromProposal($part) + [
                'organization_id' => $invoice->organization_id, 'line_type' => $this->lineTypeForCatalog($part->catalog_item_type), 'description' => $part->description,
                'quantity_millis' => $adjustment ? $this->quantityToMillis((string) $adjustment->approved_quantity) : ($part->catalog_quantity_millis ?? $this->quantityToMillis((string) $part->quantity)),
                'unit' => $adjustment?->approved_unit ?? $part->unit, 'unit_price_cents' => $part->catalog_item_type ? $part->catalog_unit_price_cents : null, 'included' => true,
                'billing_treatment' => 'billable', 'taxable' => $part->catalog_item_type ? (bool) $part->catalog_taxable : true, 'source_visit_id' => $part->visit_id,
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
        $this->refreshLegacyLaborDescriptions($invoice);
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
            $organization = Organization::query()->with('currentFullLogo')->findOrFail($invoice->organization_id);
            if ($organization->isBillingProfileComplete()) {
                $invoice->update($this->sellerSnapshot($organization));
            }
            $this->calculator->recalculate($invoice);
            $this->refreshLegacyLaborDescriptions($invoice);
            $this->validateForIssue($invoice);
            $this->serviceSnapshots->createForIssue($invoice, $actor);
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
            if ($invoice->successfulPaymentsCents() > 0) {
                throw ValidationException::withMessages(['invoice' => 'An invoice with a successful payment cannot be voided or reissued. Use refund or reversal history.']);
            }
            if ($invoice->status === 'void') {
                return Invoice::query()->where('reissue_of_invoice_id', $invoice->id)->latest('generation')->firstOrFail();
            }
            $handoff = null;
            if ($invoice->billing_handoff_id !== null) {
                $handoff = BillingHandoff::query()->lockForUpdate()->findOrFail($invoice->billing_handoff_id);
                abort_unless((int) $handoff->current_invoice_id === (int) $invoice->id, 409);
            } else {
                abort_unless($invoice->service_ticket_id === null, 409);
            }
            $invoice->update(['status' => 'void', 'voided_at' => now(), 'voided_by_id' => $actor->id, 'void_reason' => $reason, 'void_token' => $token]);
            $copy = Arr::except($invoice->getAttributes(), ['id', 'invoice_number', 'status', 'generation', 'reissue_of_invoice_id', 'creation_token', 'issue_token', 'issued_at', 'issued_by_id', 'voided_at', 'voided_by_id', 'void_reason', 'void_token', 'pdf_status', 'pdf_disk', 'pdf_key', 'pdf_sha256', 'pdf_failure_code', 'electronic_payment_provider', 'payment_provider_locked_at', 'created_at', 'updated_at']);
            $replacement = Invoice::query()->create($copy + [
                'invoice_number' => $this->numbers->next($invoice->organization), 'status' => 'draft', 'generation' => $invoice->generation + 1,
                'reissue_of_invoice_id' => $invoice->id, 'creation_token' => $token, 'pdf_status' => 'not_requested', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            foreach ($invoice->lines as $line) {
                $replacement->lines()->create(Arr::except($line->getAttributes(), ['id', 'invoice_id', 'catalog_package_recipe_snapshot', 'created_at', 'updated_at']) + [
                    'organization_id' => $invoice->organization_id,
                    'catalog_package_recipe_snapshot' => $line->catalog_package_recipe_snapshot,
                ]);
            }
            foreach ($invoice->closeoutLinks as $link) {
                $replacement->closeoutLinks()->create(Arr::except($link->getAttributes(), ['id', 'invoice_id', 'created_at', 'updated_at']) + ['organization_id' => $invoice->organization_id]);
            }
            $handoff?->update(['current_invoice_id' => $replacement->id]);
            $this->audit->record($invoice->organization, $actor, 'invoice.voided', $invoice, ['invoice_id' => $invoice->id, 'replacement_invoice_id' => $replacement->id, 'changed_fields' => ['status', 'voided_at']]);
            $this->audit->record($invoice->organization, $actor, 'invoice.reissued', $replacement, ['invoice_id' => $replacement->id, 'reissue_of_invoice_id' => $invoice->id]);

            return $replacement;
        });
    }

    /** @return array<string, mixed> */
    private function catalogSnapshotFromProposal(VisitPartProposal $part): array
    {
        if (! $part->catalog_item_type) {
            return [];
        }

        return Arr::only($part->getAttributes(), [
            'catalog_item_type', 'catalog_service_id', 'catalog_service_variant_id',
            'catalog_product_id', 'catalog_package_id', 'catalog_code_snapshot',
            'catalog_name_snapshot', 'catalog_description_snapshot',
            'catalog_unit_code_snapshot', 'catalog_unit_name_snapshot',
            'catalog_quantity_millis', 'catalog_original_unit_price_cents',
            'catalog_unit_price_cents', 'catalog_taxable',
            'catalog_selected_by_id', 'catalog_selected_at',
        ]) + ['catalog_package_recipe_snapshot' => $part->catalog_package_recipe_snapshot];
    }

    private function lineTypeForCatalog(?string $type): string
    {
        return match ($type) {
            'service', 'package' => 'service_charge',
            'product' => 'part',
            default => 'part',
        };
    }

    /** @return array<string, int|string|null> */
    private function lineRemovalProvenance(InvoiceLine $line): array
    {
        return [
            'source_visit_id' => $line->source_visit_id,
            'source_closeout_id' => $line->source_closeout_id,
            'source_review_id' => $line->source_review_id,
            'source_time_entry_id' => $line->source_time_entry_id,
            'source_travel_seconds' => $line->source_travel_seconds,
            'source_part_proposal_id' => $line->source_part_proposal_id,
            'labor_rate_id' => $line->labor_rate_id,
            'catalog_item_type' => $line->catalog_item_type,
            'catalog_source_id' => $line->catalog_service_id ?: ($line->catalog_product_id ?: $line->catalog_package_id),
            'catalog_service_variant_id' => $line->catalog_service_variant_id,
            'catalog_code_snapshot' => $line->catalog_code_snapshot,
        ];
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(Organization $organization): array
    {
        return [
            'seller_name' => $organization->name,
            'seller_legal_name' => $organization->legal_name,
            'seller_email' => $organization->email,
            'seller_phone' => $organization->phone,
            'seller_address_line_1' => $organization->address_line_1,
            'seller_address_line_2' => $organization->address_line_2,
            'seller_city' => $organization->city,
            'seller_state' => $organization->state,
            'seller_postal_code' => $organization->postal_code,
            'seller_logo_asset_id' => $organization->full_logo_asset_id,
        ];
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
        $organization = Organization::query()->findOrFail($invoice->organization_id);
        if (! $organization->isBillingProfileComplete()) {
            throw ValidationException::withMessages(['billing_profile' => 'Complete the organization profile in Settings before issuing.']);
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
        if ($invoice->tax_rate_basis_points !== ($settings?->default_tax_rate_basis_points ?? 0) && blank($invoice->tax_override_reason)) {
            throw ValidationException::withMessages(['tax_override_reason' => 'A reason is required when overriding the default tax rate.']);
        }
        if ($invoice->discount_type && blank($invoice->discount_reason)) {
            throw ValidationException::withMessages(['discount_reason' => 'A reason is required for a discount.']);
        }
        $rawVisitDescription = $invoice->lines()->whereNotNull('source_visit_id')->get()
            ->first(fn (InvoiceLine $line): bool => str_contains($line->description, 'Visit #'.$line->source_visit_id));
        if ($rawVisitDescription) {
            throw ValidationException::withMessages(['lines' => 'Remove the internal Visit database ID from customer-facing invoice line descriptions before issue.']);
        }
    }

    private function refreshLegacyLaborDescriptions(Invoice $invoice): void
    {
        $lines = $invoice->lines()->where('line_type', 'labor')->whereNotNull('source_visit_id')->with('sourceVisit.serviceTicket')->get();
        $multipleVisits = $lines->pluck('source_visit_id')->unique()->count() > 1;
        foreach ($lines as $line) {
            $ticket = $line->sourceVisit?->serviceTicket;
            $legacy = $ticket ? "Visit #{$line->source_visit_id} — {$ticket->ticket_number}: {$ticket->title}" : null;
            if ($legacy && $line->description === $legacy) {
                $line->update(['description' => $this->customerLaborDescription($line->sourceVisit, $ticket->title, $multipleVisits)]);
            }
        }
    }

    private function customerLaborDescription(Visit $visit, string $ticketTitle, bool $includeDate): string
    {
        $description = 'Service Labor — '.$ticketTitle;
        if ($includeDate && $visit->scheduledStartLocal()) {
            $description .= ' — '.$visit->scheduledStartLocal()->format('M j, Y');
        }

        return $description;
    }

    private function quantityToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }
}
