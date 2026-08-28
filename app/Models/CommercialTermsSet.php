<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'code', 'name', 'version', 'body', 'approved', 'active', 'created_by_id'])]
class CommercialTermsSet extends Model
{
    protected function casts(): array
    {
        return ['approved' => 'boolean', 'active' => 'boolean'];
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
