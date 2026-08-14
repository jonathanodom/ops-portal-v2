<?php

namespace App\Domain;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OrganizationBillingSetting;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovedVisitLaborWorkflow
{
    public function __construct(
        private readonly ApprovedVisitLaborMinutes $minutes,
        private readonly LaborServiceResolver $laborServices,
        private readonly BillableLaborCalculator $laborCalculator,
        private readonly CatalogLineSnapshotFactory $catalogSnapshots,
        private readonly InvoiceCalculator $invoiceCalculator,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{eligible: Collection<int, array<string, mixed>>, billed: Collection<int, array<string, mixed>>} */
    public function candidates(Invoice $invoice): array
    {
        if (! $invoice->isEditable()) {
            return ['eligible' => collect(), 'billed' => collect()];
        }
        $visits = Visit::query()
            ->forOrganization($invoice->organization_id)
            ->where('status', 'approved')
            ->where('service_location_id', $invoice->service_location_id)
            ->whereHas('serviceTicket', fn ($query) => $query->where('customer_id', $invoice->customer_id))
            ->whereHas('currentCloseout', fn ($query) => $query
                ->where('status', 'submitted')
                ->whereHas('reviews', fn ($reviews) => $reviews->where('decision', 'approved')))
            ->with([
                'returnOfVisit',
                'serviceTicket',
                'currentCloseout.visit.timeEntries',
                'currentCloseout.reviews.adjustments',
            ])
            ->orderByDesc('scheduled_start_at')
            ->orderByDesc('id')
            ->get();
        $represented = InvoiceLine::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('line_type', 'labor')
            ->whereIn('source_visit_id', $visits->modelKeys())
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'void'))
            ->with('invoice:id,invoice_number,status')
            ->get()
            ->keyBy('source_visit_id');
        $eligible = collect();
        $billed = collect();
        foreach ($visits as $visit) {
            $closeout = $visit->currentCloseout;
            $review = $closeout?->reviews->where('decision', 'approved')->sortByDesc('id')->first();
            $approvedMinutes = $closeout ? $this->minutes->calculate($closeout) : 0;
            if (! $review || $approvedMinutes <= 0) {
                continue;
            }
            $candidate = compact('visit', 'closeout', 'review', 'approvedMinutes');
            if ($line = $represented->get($visit->id)) {
                $billed->push($candidate + ['line' => $line, 'invoice' => $line->invoice]);
            } else {
                $eligible->push($candidate);
            }
        }

        return compact('eligible', 'billed');
    }

    public function attach(Invoice $invoice, Visit $visit, User $actor): InvoiceLine
    {
        return DB::transaction(function () use ($invoice, $visit, $actor): InvoiceLine {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if (! $invoice->isEditable()) {
                throw ValidationException::withMessages(['invoice' => 'Only a Draft or Ready for Review invoice can receive approved Visit labor.']);
            }
            $visit = Visit::query()
                ->forOrganization($invoice->organization_id)
                ->withTrashed()
                ->with([
                    'serviceTicket',
                    'currentCloseout.visit.timeEntries',
                    'currentCloseout.reviews.adjustments',
                ])
                ->lockForUpdate()
                ->findOrFail($visit->id);
            if ($visit->trashed() || $visit->status !== 'approved') {
                throw ValidationException::withMessages(['visit' => 'Select a current approved Visit.']);
            }
            if ((int) $visit->serviceTicket->customer_id !== (int) $invoice->customer_id
                || (int) $visit->service_location_id !== (int) $invoice->service_location_id) {
                throw ValidationException::withMessages(['visit' => 'The approved Visit must belong to this invoice customer and service location.']);
            }
            $closeout = $visit->currentCloseout;
            $review = $closeout?->reviews->where('decision', 'approved')->sortByDesc('id')->first();
            if (! $closeout || $closeout->status !== 'submitted' || ! $review) {
                throw ValidationException::withMessages(['visit' => 'The Visit requires its current submitted Closeout and an approved review.']);
            }
            $approvedMinutes = $this->minutes->calculate($closeout);
            if ($approvedMinutes <= 0) {
                throw ValidationException::withMessages(['visit' => 'The approved review contains no billable on-site or other labor.']);
            }
            $represented = InvoiceLine::query()
                ->where('organization_id', $invoice->organization_id)
                ->where('source_visit_id', $visit->id)
                ->where('line_type', 'labor')
                ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'void'))
                ->lockForUpdate()
                ->first();
            if ($represented) {
                throw ValidationException::withMessages([
                    'visit' => 'This Visit labor is already represented on '.$represented->invoice()->value('invoice_number').'.',
                ]);
            }

            $settings = OrganizationBillingSetting::query()->where('organization_id', $invoice->organization_id)->lockForUpdate()->first();
            if (! $settings) {
                throw ValidationException::withMessages(['labor_service' => 'Configure Billing labor policy before adding approved Visit labor.']);
            }
            $laborService = $this->laborServices->resolve($invoice->organization_id);
            $calculation = $this->laborCalculator->calculate(
                $approvedMinutes,
                (int) $settings->labor_billing_increment_minutes,
                $settings->labor_rounding_rule,
                (int) $settings->minimum_billable_minutes,
            );
            $snapshot = $this->catalogSnapshots->create(
                $invoice->organization_id,
                'service',
                $laborService->id,
                $calculation['quantity_millis'],
            );
            $description = 'Service Labor — '.$visit->serviceTicket->title;
            if ($visit->scheduledStartLocal()) {
                $description .= ' — '.$visit->scheduledStartLocal()->format('M j, Y');
            }
            $line = $invoice->lines()->create($snapshot + [
                'organization_id' => $invoice->organization_id,
                'line_type' => 'labor',
                'description' => $description,
                'quantity_millis' => $calculation['quantity_millis'],
                'unit' => $snapshot['catalog_unit_name_snapshot'],
                'unit_price_cents' => $snapshot['catalog_unit_price_cents'],
                'labor_rate_id' => null,
                'included' => true,
                'billing_treatment' => 'billable',
                'taxable' => $snapshot['catalog_taxable'],
                'catalog_selected_by_id' => $actor->id,
                'catalog_selected_at' => now(),
                'source_visit_id' => $visit->id,
                'source_closeout_id' => $closeout->id,
                'source_review_id' => $review->id,
                'sort_order' => ((int) $invoice->lines()->max('sort_order')) + 10,
            ]);
            $this->invoiceCalculator->recalculate($invoice);
            $this->audit->record($invoice->organization, $actor, 'invoice.approved_visit_labor_added', $line, [
                'invoice_id' => $invoice->id,
                'visit_id' => $visit->id,
                'closeout_id' => $closeout->id,
                'review_id' => $review->id,
                'approved_minutes' => $approvedMinutes,
                'billable_minutes' => $calculation['billable_minutes'],
                'catalog_service_id' => $laborService->id,
                'changed_fields' => ['lines', 'subtotal_cents', 'tax_total_cents', 'total_cents'],
            ]);

            return $line->fresh();
        });
    }
}
