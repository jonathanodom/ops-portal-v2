<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'proposal_publication_id', 'name', 'email', 'token_hash', 'created_by_id', 'revoked_at', 'revoked_by_id'])]
class ProposalRecipient extends Model
{
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }
}
