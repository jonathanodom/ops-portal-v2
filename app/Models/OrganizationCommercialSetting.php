<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'default_proposal_expiration_days', 'gross_margin_floor_bps', 'discount_approval_ceiling_bps', 'approve_manual_price_overrides', 'approve_below_cost_lines', 'approve_terms_overrides', 'first_reminder_days', 'second_reminder_days', 'customer_show_line_details', 'customer_show_optional_items', 'signature_statement_version', 'notification_policy', 'customer_show_location_totals', 'customer_labor_grouping', 'customer_show_manufacturer_model', 'customer_show_product_images', 'customer_show_package_components'])]
class OrganizationCommercialSetting extends Model
{
    protected function casts(): array
    {
        return ['approve_manual_price_overrides' => 'boolean', 'approve_below_cost_lines' => 'boolean', 'approve_terms_overrides' => 'boolean', 'customer_show_line_details' => 'boolean', 'customer_show_optional_items' => 'boolean', 'customer_show_location_totals' => 'boolean', 'customer_show_manufacturer_model' => 'boolean', 'customer_show_product_images' => 'boolean', 'customer_show_package_components' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
