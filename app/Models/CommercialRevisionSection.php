<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'commercial_revision_id', 'source_content_block_id', 'heading', 'body', 'customer_visible', 'sort_order'])]
class CommercialRevisionSection extends Model
{
    protected function casts(): array
    {
        return ['customer_visible' => 'boolean'];
    }
}
