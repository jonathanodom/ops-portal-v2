<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'proposal_publication_id', 'name', 'email', 'token_hash', 'created_by_id', 'first_viewed_at', 'last_viewed_at', 'revoked_at', 'revoked_by_id'])]
class ProposalRecipient extends Model
{
    public function publication(): BelongsTo
    {
        return $this->belongsTo(ProposalPublication::class, 'proposal_publication_id');
    }

    protected function casts(): array
    {
        return ['first_viewed_at' => 'datetime', 'last_viewed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
