<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\UnitOfMeasure;

class CatalogDefaults
{
    public const UNITS = [
        ['code' => 'each', 'name' => 'Each', 'symbol' => 'ea', 'dimension' => 'count', 'decimal_places' => 0],
        ['code' => 'foot', 'name' => 'Foot', 'symbol' => 'ft', 'dimension' => 'length', 'decimal_places' => 2],
        ['code' => 'hour', 'name' => 'Hour', 'symbol' => 'hr', 'dimension' => 'time', 'decimal_places' => 3],
        ['code' => 'visit', 'name' => 'Visit', 'symbol' => null, 'dimension' => 'service', 'decimal_places' => 0],
        ['code' => 'location', 'name' => 'Location', 'symbol' => null, 'dimension' => 'service', 'decimal_places' => 0],
        ['code' => 'month', 'name' => 'Month', 'symbol' => 'mo', 'dimension' => 'period', 'decimal_places' => 0],
        ['code' => 'box', 'name' => 'Box', 'symbol' => null, 'dimension' => 'package', 'decimal_places' => 0],
        ['code' => 'roll', 'name' => 'Roll', 'symbol' => null, 'dimension' => 'package', 'decimal_places' => 0],
        ['code' => 'bag', 'name' => 'Bag', 'symbol' => null, 'dimension' => 'package', 'decimal_places' => 0],
        ['code' => 'case', 'name' => 'Case', 'symbol' => null, 'dimension' => 'package', 'decimal_places' => 0],
    ];

    public function ensureFor(Organization $organization): void
    {
        foreach (self::UNITS as $unit) {
            UnitOfMeasure::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => $unit['code']],
                $unit + ['active' => true],
            );
        }
    }
}
