<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'category_id', 'base_uom_id', 'default_sales_uom_id', 'product_code', 'sku', 'name', 'manufacturer', 'model', 'customer_description', 'internal_description', 'sales_quantity_millis', 'default_cost_cents', 'default_cost_quantity_millis', 'default_sell_price_cents', 'taxable', 'tracking_type', 'active', 'created_by_id', 'updated_by_id'])]
class CatalogProduct extends Model
{
    public const TRACKING_TYPES = ['standard', 'serialized', 'lot_or_roll'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_uom_id');
    }

    public function defaultSalesUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'default_sales_uom_id');
    }

    public function purchaseUnits(): HasMany
    {
        return $this->hasMany(CatalogProductPurchaseUnit::class)->orderByDesc('is_default')->orderBy('label')->orderBy('id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
