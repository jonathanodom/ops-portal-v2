<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'heading', 'body', 'active', 'created_by_id', 'updated_by_id'])]
class CommercialContentBlock extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
