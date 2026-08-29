<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'proposal_publication_id', 'proposal_recipient_id', 'proposal_share_link_id', 'parent_id', 'staff_user_id', 'author_type', 'author_name', 'author_email', 'target_type', 'target_reference', 'body'])]
class ProposalComment extends Model
{
    protected function casts(): array
    {
        return ['author_name' => 'encrypted', 'author_email' => 'encrypted'];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ProposalPublication::class, 'proposal_publication_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
