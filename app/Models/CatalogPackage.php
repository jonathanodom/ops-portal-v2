<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'category_id', 'sales_uom_id', 'package_code', 'name', 'customer_description', 'internal_description', 'pricing_model', 'default_price_cents', 'taxable', 'active', 'created_by_id', 'updated_by_id'])]
class CatalogPackage extends Model
{
    public const PRICING_MODELS = ['flat', 'component_sum', 'quote_required'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function salesUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'sales_uom_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(CatalogPackageComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'active' => 'boolean'];
    }
}
