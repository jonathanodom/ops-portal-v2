<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'visit_time_entry_id', 'sequence', 'reason', 'allocated_by_id'])]
class VisitTimeAllocationSet extends Model
{
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(VisitTimeEntry::class, 'visit_time_entry_id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VisitTimeAllocation::class)->orderBy('position');
    }
}
