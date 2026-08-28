<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'template_type', 'name', 'acceptance_enabled', 'active', 'created_by_id', 'updated_by_id'])]
class ProposalTemplate extends Model
{
    protected function casts(): array
    {
        return ['acceptance_enabled' => 'boolean', 'active' => 'boolean'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ProposalTemplateSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
