<?php

namespace App\Support\Api\V1;

use App\Models\ServiceLocation;

/**
 * Shapes a ServiceLocation as the LocationSummary DTO from
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.2.
 */
final class LocationSummary
{
    /** @return array<string, mixed> */
    public static function make(ServiceLocation $location): array
    {
        return [
            'id' => (string) $location->id,
            'customer_id' => (string) $location->customer_id,
            'name' => $location->name,
            'address' => [
                'line1' => $location->address_line_1,
                'line2' => $location->address_line_2,
                'city' => $location->city,
                'state' => $location->state,
                'postal_code' => $location->postal_code,
            ],
            'timezone' => $location->timezone,
        ];
    }
}
