<?php

namespace App\Domain;

use App\Models\CatalogService;
use App\Models\OrganizationBillingSetting;
use Illuminate\Validation\ValidationException;

class LaborServiceResolver
{
    public function resolve(int $organizationId, ?int $approvedOverrideServiceId = null): CatalogService
    {
        if ($approvedOverrideServiceId !== null) {
            $service = CatalogService::query()
                ->forOrganization($organizationId)
                ->with('salesUom')
                ->find($approvedOverrideServiceId);
            if (! $service) {
                throw ValidationException::withMessages([
                    'labor_service' => 'The approved labor override must use a Catalog Service from this Organization.',
                ]);
            }

            return $this->assertBillableHourlyService($service, 'approved labor override');
        }

        $serviceId = OrganizationBillingSetting::query()
            ->where('organization_id', $organizationId)
            ->value('default_labor_catalog_service_id');
        if (! $serviceId) {
            throw ValidationException::withMessages([
                'labor_service' => 'Configure a default hourly Catalog labor service in Billing Settings before creating labor charges.',
            ]);
        }
        $service = CatalogService::query()
            ->forOrganization($organizationId)
            ->with('salesUom')
            ->find($serviceId);
        if (! $service) {
            throw ValidationException::withMessages([
                'labor_service' => 'The configured default labor service is not available to this Organization.',
            ]);
        }

        return $this->assertBillableHourlyService($service, 'default labor service');
    }

    private function assertBillableHourlyService(CatalogService $service, string $source): CatalogService
    {
        if (! $service->active) {
            throw ValidationException::withMessages(['labor_service' => "The {$source} must be active."]);
        }
        if ($service->pricing_model !== 'hourly') {
            throw ValidationException::withMessages(['labor_service' => "The {$source} must use hourly pricing."]);
        }
        if ($service->default_price_cents === null) {
            throw ValidationException::withMessages(['labor_service' => "The {$source} requires a configured hourly price."]);
        }
        if (! $service->salesUom || $service->salesUom->code !== 'hour' || $service->salesUom->dimension !== 'time') {
            throw ValidationException::withMessages(['labor_service' => "The {$source} must use the Organization's Hour unit."]);
        }

        return $service;
    }
}
