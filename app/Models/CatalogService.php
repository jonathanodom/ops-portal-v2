<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'category_id', 'sales_uom_id', 'service_code', 'name', 'customer_description', 'internal_description', 'internal_scope', 'internal_exclusions', 'pricing_model', 'default_price_cents', 'taxable', 'estimated_duration_minutes', 'customer_visible', 'requires_office_approval', 'billing_cadence', 'billing_interval', 'active', 'created_by_id', 'updated_by_id'])]
class CatalogService extends Model
{
    public const PRICING_MODELS = ['flat', 'hourly', 'per_unit', 'variant', 'recurring', 'quote_required'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'customer_visible' => 'boolean', 'requires_office_approval' => 'boolean', 'active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function salesUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'sales_uom_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CatalogServiceVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function packageComponents(): HasMany
    {
        return $this->hasMany(CatalogPackageComponent::class, 'catalog_service_id');
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'catalog_service_addons', 'catalog_service_id', 'addon_service_id')->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
