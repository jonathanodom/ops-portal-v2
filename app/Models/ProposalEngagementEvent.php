<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'proposal_publication_id', 'proposal_recipient_id', 'proposal_share_link_id', 'actor_id', 'event_type', 'target_type', 'target_reference', 'encrypted_ip', 'ip_hash', 'user_agent', 'safe_metadata', 'owner_notified_at', 'occurred_at'])]
class ProposalEngagementEvent extends Model
{
    protected function casts(): array
    {
        return ['encrypted_ip' => 'encrypted', 'safe_metadata' => 'array', 'owner_notified_at' => 'datetime', 'occurred_at' => 'datetime'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ProposalPublication::class, 'proposal_publication_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(ProposalRecipient::class, 'proposal_recipient_id');
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ProposalShareLink::class, 'proposal_share_link_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
