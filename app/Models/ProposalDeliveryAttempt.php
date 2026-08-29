<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'proposal_publication_id', 'proposal_recipient_id', 'delivery_type', 'status', 'idempotency_key', 'safe_failure_code', 'attempted_at', 'completed_at'])]
class ProposalDeliveryAttempt extends Model
{
    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ProposalPublication::class, 'proposal_publication_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(ProposalRecipient::class, 'proposal_recipient_id');
    }
}
