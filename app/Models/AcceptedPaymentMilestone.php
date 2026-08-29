<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'proposal_acceptance_id', 'source_milestone_id', 'invoice_id', 'name', 'amount_type', 'amount_value', 'allocated_cents', 'allocation_snapshot', 'is_balancing', 'sort_order'])]
class AcceptedPaymentMilestone extends Model
{
    protected function casts(): array
    {
        return ['is_balancing' => 'boolean', 'allocation_snapshot' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
