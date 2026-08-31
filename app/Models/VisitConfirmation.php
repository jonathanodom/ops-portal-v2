<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'visit_id', 'schedule_version', 'method', 'confirmed_by_id',
    'confirmed_at', 'note', 'scheduled_start_at', 'scheduled_end_at',
])]
class VisitConfirmation extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'schedule_version' => 'integer',
            'confirmed_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }
}
