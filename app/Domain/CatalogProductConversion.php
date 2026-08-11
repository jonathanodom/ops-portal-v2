<?php

namespace App\Domain;

use App\Models\CatalogProductPurchaseUnit;
use Illuminate\Validation\ValidationException;

class CatalogProductConversion
{
    public const SCALE = 1000;

    public function purchaseQuantityToBaseMillis(CatalogProductPurchaseUnit $purchaseUnit, int $purchaseQuantityMillis): int
    {
        if ($purchaseQuantityMillis < 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity cannot be negative.']);
        }

        if ($purchaseUnit->base_quantity_millis < 1) {
            throw ValidationException::withMessages(['purchase_unit' => 'Purchase conversion must be greater than zero.']);
        }

        $product = $purchaseUnit->product;
        if (! $product || $product->organization_id !== $purchaseUnit->organization_id) {
            throw ValidationException::withMessages(['purchase_unit' => 'Purchase conversion does not belong to this product organization.']);
        }

        if ($purchaseQuantityMillis > intdiv(PHP_INT_MAX - intdiv(self::SCALE, 2), $purchaseUnit->base_quantity_millis)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity is too large to convert safely.']);
        }

        $numerator = $purchaseQuantityMillis * $purchaseUnit->base_quantity_millis;

        return intdiv($numerator + intdiv(self::SCALE, 2), self::SCALE);
    }
}
