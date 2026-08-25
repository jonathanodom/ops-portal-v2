<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'visit_id', 'closeout_id', 'service_ticket_work_item_id', 'user_id', 'active_user_id', 'category', 'started_at', 'ended_at', 'corrected_started_at', 'corrected_ended_at', 'source', 'note', 'correction_reason'])]
class VisitTimeEntry extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'corrected_started_at' => 'datetime',
            'corrected_ended_at' => 'datetime',
        ];
    }

    public function getEffectiveStartedAtAttribute()
    {
        return $this->corrected_started_at ?? $this->started_at;
    }

    public function getEffectiveEndedAtAttribute()
    {
        return $this->corrected_ended_at ?? $this->ended_at;
    }

    public function effectiveDurationSeconds(): int
    {
        return $this->effective_ended_at
            ? (int) $this->effective_started_at->diffInSeconds($this->effective_ended_at)
            : 0;
    }

    public function hasSubmittedCorrection(): bool
    {
        return $this->corrected_started_at !== null || $this->corrected_ended_at !== null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class)->withTrashed();
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(VisitTimeEntryCorrection::class)->orderBy('sequence');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(ServiceTicketWorkItem::class, 'service_ticket_work_item_id');
    }

    public function allocationSets(): HasMany
    {
        return $this->hasMany(VisitTimeAllocationSet::class)->orderBy('sequence');
    }
}
