<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'proposal_acceptance_id', 'publication_line_id', 'optional', 'included', 'line_snapshot'])]
class ProposalAcceptanceLineSelection extends Model
{
    protected function casts(): array
    {
        return ['optional' => 'boolean', 'included' => 'boolean', 'line_snapshot' => 'array'];
    }
}
