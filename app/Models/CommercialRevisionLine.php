<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'commercial_revision_id', 'location_id', 'system_id', 'phase_id', 'catalog_category_id', 'line_type', 'catalog_product_id', 'catalog_service_id', 'catalog_service_variant_id', 'catalog_package_id', 'source_code', 'description', 'customer_description', 'unit_code', 'unit_name', 'quantity_millis', 'catalog_unit_sell_cents', 'effective_unit_sell_cents', 'sell_price_overridden', 'cost_basis_cents', 'cost_basis_quantity_millis', 'cost_resolved', 'discount_type', 'discount_value', 'optional', 'included', 'taxable', 'sort_order', 'gross_sell_cents', 'line_discount_cents', 'quote_discount_cents', 'tax_cents', 'total_cents', 'resolved_cost_cents'])]
class CommercialRevisionLine extends Model
{
    protected function casts(): array
    {
        return ['sell_price_overridden' => 'boolean', 'cost_resolved' => 'boolean', 'optional' => 'boolean', 'included' => 'boolean', 'taxable' => 'boolean'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CommercialRevision::class, 'commercial_revision_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(CommercialRevisionLocation::class, 'location_id');
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(CommercialRevisionSystem::class, 'system_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(CommercialRevisionPhase::class, 'phase_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'catalog_category_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(CommercialRevisionLineComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
