<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'visit_id', 'closeout_id', 'source_proposal_id', 'proposed_by_id', 'description', 'quantity', 'unit', 'serial_mac', 'billing_treatment', 'technician_note', 'removed_at', 'catalog_item_type', 'catalog_service_id', 'catalog_service_variant_id', 'catalog_product_id', 'catalog_package_id', 'catalog_code_snapshot', 'catalog_name_snapshot', 'catalog_description_snapshot', 'catalog_unit_code_snapshot', 'catalog_unit_name_snapshot', 'catalog_quantity_millis', 'catalog_original_unit_price_cents', 'catalog_unit_price_cents', 'catalog_taxable', 'catalog_package_recipe_snapshot', 'catalog_selected_by_id', 'catalog_selected_at'])]
class VisitPartProposal extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'removed_at' => 'datetime', 'catalog_taxable' => 'boolean', 'catalog_package_recipe_snapshot' => 'array', 'catalog_selected_at' => 'datetime'];
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function sourceProposal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_proposal_id');
    }

    public function catalogService(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class);
    }

    public function catalogServiceVariant(): BelongsTo
    {
        return $this->belongsTo(CatalogServiceVariant::class);
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function catalogPackage(): BelongsTo
    {
        return $this->belongsTo(CatalogPackage::class);
    }

    public function catalogSelectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'catalog_selected_by_id');
    }
}
