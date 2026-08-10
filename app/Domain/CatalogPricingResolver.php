<?php

namespace App\Domain;

use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use Illuminate\Validation\ValidationException;

class CatalogPricingResolver
{
    public function servicePrice(CatalogService $service, ?CatalogServiceVariant $variant = null): ?int
    {
        if (! $service->active) {
            throw ValidationException::withMessages(['service' => 'This service is inactive.']);
        }
        if ($service->pricing_model === 'quote_required') {
            return null;
        }
        if ($service->pricing_model === 'variant') {
            if (! $variant || ! $variant->active || (int) $variant->catalog_service_id !== (int) $service->id || (int) $variant->organization_id !== (int) $service->organization_id) {
                throw ValidationException::withMessages(['variant' => 'Choose an active variant for this service.']);
            }
            $price = $variant->price_override_cents ?? $service->default_price_cents;
            if ($price === null) {
                throw ValidationException::withMessages(['variant' => 'The selected variant does not have a configured price.']);
            }

            return (int) $price;
        }
        if ($service->default_price_cents === null) {
            throw ValidationException::withMessages(['service' => 'This service does not have a configured price.']);
        }

        return (int) $service->default_price_cents;
    }
}
