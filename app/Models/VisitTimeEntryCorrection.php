<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'visit_time_entry_id', 'sequence', 'previous_started_at', 'previous_ended_at', 'corrected_started_at', 'corrected_ended_at', 'reason', 'corrected_by_id'])]
class VisitTimeEntryCorrection extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Submitted Visit time correction history is immutable.'));
        static::deleting(fn () => throw new LogicException('Submitted Visit time correction history is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'previous_started_at' => 'datetime',
            'previous_ended_at' => 'datetime',
            'corrected_started_at' => 'datetime',
            'corrected_ended_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(VisitTimeEntry::class, 'visit_time_entry_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_id');
    }
}
