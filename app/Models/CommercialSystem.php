<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'sort_order', 'active'])]
class CommercialSystem extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
