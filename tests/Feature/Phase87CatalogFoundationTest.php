<?php

namespace Tests\Feature;

use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase87CatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_newday_labor_services_are_organization_scoped_hourly_catalog_records(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = User::factory()->create();

        $result = app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization, $actor);

        $this->assertSame([
            'LABOR-RES-IT',
            'LABOR-RES-TECH',
            'LABOR-BUS',
            'LABOR-PROJECT',
            'LABOR-ENG',
        ], $result['created']);
        $this->assertSame([], $result['unchanged']);
        $hour = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'hour')->firstOrFail();
        $expected = collect(NewDayCatalogBootstrap::LABOR_SERVICES)->keyBy('service_code');
        $services = CatalogService::query()->forOrganization($organization->id)->whereIn('service_code', $expected->keys())->get();

        $this->assertCount(5, $services);
        foreach ($services as $service) {
            $definition = $expected->get($service->service_code);
            $this->assertSame('hourly', $service->pricing_model);
            $this->assertSame($hour->id, $service->sales_uom_id);
            $this->assertSame($definition['name'], $service->name);
            $this->assertSame($definition['customer_description'], $service->customer_description);
            $this->assertSame($definition['default_price_cents'], $service->default_price_cents);
            $this->assertTrue($service->active);
            $this->assertTrue($service->customer_visible);
        }
        $this->assertSame(0, CatalogService::query()->forOrganization($other->id)->count());
        $this->assertSame(5, AuditEvent::query()->where('organization_id', $organization->id)->where('event_type', 'catalog.bootstrap_service_created')->count());
    }

    public function test_labor_catalog_bootstrap_is_idempotent_and_does_not_overwrite_existing_catalog_prices(): void
    {
        $organization = Organization::factory()->create();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->ensureLaborServices($organization);
        CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->update([
            'name' => 'Customized Business Labor',
            'default_price_cents' => 15000,
        ]);

        $result = $bootstrap->ensureLaborServices($organization);

        $this->assertSame([], $result['created']);
        $this->assertSame([
            'LABOR-RES-IT',
            'LABOR-RES-TECH',
            'LABOR-BUS',
            'LABOR-PROJECT',
            'LABOR-ENG',
        ], $result['unchanged']);
        $this->assertSame(5, CatalogService::query()->forOrganization($organization->id)->whereIn('service_code', collect(NewDayCatalogBootstrap::LABOR_SERVICES)->pluck('service_code'))->count());
        $this->assertDatabaseHas('catalog_services', [
            'organization_id' => $organization->id,
            'service_code' => 'LABOR-BUS',
            'name' => 'Customized Business Labor',
            'default_price_cents' => 15000,
        ]);
    }
}
