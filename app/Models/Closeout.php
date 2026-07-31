<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'visit_id', 'version', 'status', 'content_version', 'outcome', 'diagnosis', 'work_performed', 'exceptions', 'recommendations', 'return_reason', 'unfinished_work', 'needed_equipment', 'hold_reason', 'unavailable_category', 'unavailable_detail', 'representative_name', 'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail', 'no_photo_category', 'no_photo_detail', 'submitted_token', 'submitted_by_id', 'submitted_at', 'return_visit_id', 'last_saved_by_id'])]
class Closeout extends Model
{
    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(VisitMedia::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(VisitTimeEntry::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(VisitPartProposal::class);
    }

    public function lastSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_saved_by_id');
    }
}
