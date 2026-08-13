<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'closeout_review_id', 'visit_id', 'catalog_service_id',
    'catalog_service_variant_id', 'recorded_travel_seconds', 'catalog_code_snapshot',
    'catalog_name_snapshot', 'catalog_description_snapshot', 'catalog_unit_code_snapshot',
    'catalog_unit_name_snapshot', 'catalog_unit_price_cents', 'catalog_taxable',
    'selected_by_id', 'selected_at',
])]
class CloseoutReviewTripCharge extends Model
{
    protected function casts(): array
    {
        return ['catalog_taxable' => 'boolean', 'selected_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(CloseoutReview::class, 'closeout_review_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'catalog_service_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CatalogServiceVariant::class, 'catalog_service_variant_id');
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_id');
    }
}
