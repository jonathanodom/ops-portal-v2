<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'proposal_publication_id', 'proposal_recipient_id', 'proposal_share_link_id', 'email', 'email_hash', 'challenge_hash', 'status', 'attempt_count', 'expires_at', 'verified_at'])]
class ProposalEmailVerification extends Model
{
    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'datetime', 'verified_at' => 'datetime'];
    }
}
