<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'closeout_id', 'reviewer_id', 'decision', 'reason', 'disposition', 'disposition_reason', 'self_review_override', 'decision_token', 'decided_at'])]
class CloseoutReview extends Model
{
    protected function casts(): array
    {
        return ['self_review_override' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CloseoutReviewAdjustment::class);
    }
}
