<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'seller_name', 'seller_legal_name', 'seller_email', 'seller_phone', 'seller_address_line_1', 'seller_address_line_2', 'seller_city', 'seller_state', 'seller_postal_code', 'default_currency', 'default_payment_terms', 'default_tax_rate_basis_points', 'updated_by_id'])]
class OrganizationBillingSetting extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isComplete(): bool
    {
        return collect(['seller_name', 'seller_email', 'seller_phone', 'seller_address_line_1', 'seller_city', 'seller_state', 'seller_postal_code'])
            ->every(fn (string $field): bool => filled($this->{$field}));
    }
}
