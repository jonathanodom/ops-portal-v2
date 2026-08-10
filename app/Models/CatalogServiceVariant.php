<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'catalog_service_id', 'code', 'label', 'customer_label', 'price_override_cents', 'estimated_duration_minutes', 'sort_order', 'active', 'created_by_id', 'updated_by_id'])]
class CatalogServiceVariant extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
