<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'catalog_package_id', 'component_type', 'catalog_product_id', 'catalog_service_id', 'component_uom_id', 'quantity_basis', 'quantity_millis', 'basis_count_millis', 'basis_quantity_millis', 'waste_basis_points', 'customer_visible', 'sort_order', 'internal_notes', 'active', 'created_by_id', 'updated_by_id'])]
class CatalogPackageComponent extends Model
{
    public const TYPES = ['product', 'service'];

    public const QUANTITY_BASES = ['direct', 'pull_allowance'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(CatalogPackage::class, 'catalog_package_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class, 'catalog_product_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    public function componentUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'component_uom_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return ['customer_visible' => 'boolean', 'active' => 'boolean'];
    }
}
