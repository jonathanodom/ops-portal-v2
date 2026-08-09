<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'invoice_id', 'line_type', 'description', 'quantity_millis', 'unit', 'unit_price_cents', 'included', 'billing_treatment', 'taxable', 'tax_rate_basis_points', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'labor_rate_id', 'source_visit_id', 'source_closeout_id', 'source_review_id', 'source_time_entry_id', 'source_part_proposal_id', 'sort_order', 'override_reason'])]
class InvoiceLine extends Model
{
    protected function casts(): array
    {
        return ['included' => 'boolean', 'taxable' => 'boolean'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function laborRate(): BelongsTo
    {
        return $this->belongsTo(BillingLaborRate::class, 'labor_rate_id');
    }

    public function sourceVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'source_visit_id')->withTrashed();
    }

    public function sourcePart(): BelongsTo
    {
        return $this->belongsTo(VisitPartProposal::class, 'source_part_proposal_id');
    }
}
