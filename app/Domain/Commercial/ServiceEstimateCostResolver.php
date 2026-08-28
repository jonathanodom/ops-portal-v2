<?php

namespace App\Domain\Commercial;

use App\Models\CatalogService;

final class ServiceEstimateCostResolver
{
    /** @return array{cost_cents:?int,basis_quantity_millis:?int,source_type:?string,source_id:?int,source_name:?string} */
    public function resolve(CatalogService $service): array
    {
        if ($service->default_internal_cost_cents !== null) {
            return [
                'cost_cents' => (int) $service->default_internal_cost_cents,
                'basis_quantity_millis' => 1000,
                'source_type' => 'service_default',
                'source_id' => $service->id,
                'source_name' => $service->name,
            ];
        }

        $role = $service->defaultLaborRole;
        if (! $role || ! $role->active) {
            return $this->unresolved();
        }

        if ($service->pricing_model === 'hourly') {
            $cost = (int) $role->hourly_cost_cents;
        } elseif ($service->pricing_model === 'flat' && $service->estimated_duration_minutes) {
            $cost = $this->roundRatio((int) $role->hourly_cost_cents * (int) $service->estimated_duration_minutes, 60);
        } else {
            return $this->unresolved();
        }

        return [
            'cost_cents' => $cost,
            'basis_quantity_millis' => 1000,
            'source_type' => 'labor_role',
            'source_id' => $role->id,
            'source_name' => $role->name,
        ];
    }

    /** @return array{cost_cents:null,basis_quantity_millis:null,source_type:null,source_id:null,source_name:null} */
    private function unresolved(): array
    {
        return ['cost_cents' => null, 'basis_quantity_millis' => null, 'source_type' => null, 'source_id' => null, 'source_name' => null];
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
