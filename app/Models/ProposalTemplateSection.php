<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['proposal_template_id', 'section_type', 'heading', 'customer_visible', 'sort_order'])]
class ProposalTemplateSection extends Model
{
    protected function casts(): array
    {
        return ['customer_visible' => 'boolean'];
    }
}
