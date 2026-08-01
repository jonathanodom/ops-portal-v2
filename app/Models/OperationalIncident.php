<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'category', 'severity', 'fingerprint', 'subject_type', 'subject_id', 'actor_id',
    'request_id', 'context', 'status', 'occurrences', 'first_occurred_at', 'last_occurred_at',
    'resolved_by_id', 'resolved_at',
])]
class OperationalIncident extends Model
{
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_occurred_at' => 'datetime',
            'last_occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }
}
