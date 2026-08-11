<?php

namespace App\Domain;

use App\Models\CatalogPackage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PackageDemandCalculator
{
    public const QUANTITY_SCALE = 1000;

    public const BASIS_POINT_SCALE = 10000;

    /** @return array{products: Collection<int, array<string, int|string>>, services: Collection<int, array<string, int|string>>} */
    public function calculate(CatalogPackage $package, int $packageQuantityMillis): array
    {
        if ($packageQuantityMillis < 1) {
            throw ValidationException::withMessages(['quantity' => 'Package quantity must be greater than zero.']);
        }

        $package->loadMissing(['components.product', 'components.service', 'components.componentUom']);
        $products = [];
        $services = [];

        foreach ($package->components->where('active', true) as $component) {
            $standard = $this->multiplyAndRound($component->quantity_millis, $packageQuantityMillis, self::QUANTITY_SCALE);
            if ($component->component_type === 'product' && $component->product) {
                $planning = $this->multiplyAndRound($standard, self::BASIS_POINT_SCALE + $component->waste_basis_points, self::BASIS_POINT_SCALE);
                $key = $component->catalog_product_id.':'.$component->component_uom_id;
                $products[$key] ??= [
                    'product_id' => $component->catalog_product_id,
                    'product_code' => $component->product->product_code,
                    'product_name' => $component->product->name,
                    'uom_id' => $component->component_uom_id,
                    'uom_name' => $component->componentUom->name,
                    'standard_quantity_millis' => 0,
                    'planning_quantity_millis' => 0,
                ];
                $products[$key]['standard_quantity_millis'] += $standard;
                $products[$key]['planning_quantity_millis'] += $planning;
            } elseif ($component->component_type === 'service' && $component->service) {
                $key = $component->catalog_service_id.':'.$component->component_uom_id;
                $services[$key] ??= [
                    'service_id' => $component->catalog_service_id,
                    'service_code' => $component->service->service_code,
                    'service_name' => $component->service->name,
                    'uom_id' => $component->component_uom_id,
                    'uom_name' => $component->componentUom->name,
                    'standard_quantity_millis' => 0,
                ];
                $services[$key]['standard_quantity_millis'] += $standard;
            }
        }

        return ['products' => collect(array_values($products)), 'services' => collect(array_values($services))];
    }

    private function multiplyAndRound(int $left, int $right, int $divisor): int
    {
        $rounding = intdiv($divisor, 2);
        if ($left > intdiv(PHP_INT_MAX - $rounding, $right)) {
            throw ValidationException::withMessages(['quantity' => 'Demand quantity is too large to calculate safely.']);
        }

        return intdiv(($left * $right) + $rounding, $divisor);
    }
}
