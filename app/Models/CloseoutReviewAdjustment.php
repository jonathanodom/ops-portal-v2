<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'closeout_review_id', 'type', 'visit_time_entry_id', 'visit_part_proposal_id', 'excluded', 'approved_minutes', 'approved_quantity', 'approved_unit', 'approved_billing_treatment', 'reason'])]
class CloseoutReviewAdjustment extends Model
{
    protected function casts(): array
    {
        return ['excluded' => 'boolean', 'approved_quantity' => 'decimal:2'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(CloseoutReview::class, 'closeout_review_id');
    }

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(VisitTimeEntry::class, 'visit_time_entry_id');
    }

    public function partProposal(): BelongsTo
    {
        return $this->belongsTo(VisitPartProposal::class, 'visit_part_proposal_id');
    }
}
