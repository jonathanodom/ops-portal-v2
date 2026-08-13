<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'seller_name', 'seller_legal_name', 'seller_email', 'seller_phone', 'seller_address_line_1', 'seller_address_line_2', 'seller_city', 'seller_state', 'seller_postal_code', 'default_currency', 'default_payment_terms', 'default_payment_provider', 'default_tax_rate_basis_points', 'default_labor_catalog_service_id', 'labor_billing_increment_minutes', 'labor_rounding_rule', 'minimum_billable_minutes', 'trip_charge_catalog_service_id', 'suggest_trip_charges', 'auto_select_trip_charges', 'updated_by_id'])]
class OrganizationBillingSetting extends Model
{
    protected function casts(): array
    {
        return [
            'suggest_trip_charges' => 'boolean',
            'auto_select_trip_charges' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function defaultLaborService(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'default_labor_catalog_service_id');
    }

    public function tripChargeService(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class, 'trip_charge_catalog_service_id');
    }
}
