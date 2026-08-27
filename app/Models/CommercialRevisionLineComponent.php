<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'commercial_revision_line_id', 'component_type', 'catalog_product_id', 'catalog_service_id', 'source_code', 'name', 'unit_code', 'unit_name', 'quantity_millis', 'waste_basis_points', 'unit_sell_cents', 'cost_basis_cents', 'cost_basis_quantity_millis', 'cost_resolved', 'customer_visible', 'sort_order'])]
class CommercialRevisionLineComponent extends Model
{
    protected function casts(): array
    {
        return ['cost_resolved' => 'boolean', 'customer_visible' => 'boolean'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(CommercialRevisionLine::class, 'commercial_revision_line_id');
    }
}
