<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'commercial_revision_id', 'content_hash', 'status', 'trigger_snapshot', 'requested_by_id', 'requested_at', 'decided_by_id', 'decision_reason', 'decided_at'])]
class CommercialRevisionApproval extends Model
{
    protected function casts(): array
    {
        return ['trigger_snapshot' => 'array', 'requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CommercialRevision::class, 'commercial_revision_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
