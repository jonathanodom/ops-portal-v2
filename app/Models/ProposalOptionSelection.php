<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'proposal_publication_id', 'proposal_recipient_id', 'proposal_share_link_id', 'publication_line_id', 'included'])]
class ProposalOptionSelection extends Model
{
    protected function casts(): array
    {
        return ['included' => 'boolean'];
    }
}
