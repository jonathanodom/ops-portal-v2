<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'code', 'name', 'symbol', 'dimension', 'decimal_places', 'active', 'created_by_id', 'updated_by_id'])]
class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function services(): HasMany
    {
        return $this->hasMany(CatalogService::class, 'sales_uom_id');
    }

    public function baseProducts(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'base_uom_id');
    }

    public function salesProducts(): HasMany
    {
        return $this->hasMany(CatalogProduct::class, 'default_sales_uom_id');
    }

    public function productPurchaseUnits(): HasMany
    {
        return $this->hasMany(CatalogProductPurchaseUnit::class, 'purchase_uom_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
