<?php

namespace Tests\Feature;

use App\Domain\CatalogLineSnapshotFactory;
use App\Domain\CatalogPricingResolver;
use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
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

    public function test_trip_charge_is_a_catalog_backed_variant_service_with_expected_prices(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $actor = User::factory()->create();

        $result = app(NewDayCatalogBootstrap::class)->ensureTripCharge($organization, $actor);

        $this->assertSame(['TRIP', 'TRIP-45-60', 'TRIP-60-PLUS'], $result['created']);
        $this->assertSame([], $result['unchanged']);
        $service = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'TRIP')->firstOrFail();
        $this->assertSame('Trip / Dispatch Charge', $service->name);
        $this->assertSame('variant', $service->pricing_model);
        $this->assertNull($service->default_price_cents);
        $this->assertFalse($service->taxable);
        $this->assertSame('visit', $service->salesUom->code);
        $this->assertSame('Trip / dispatch charge based on en-route travel time to the service location.', $service->customer_description);

        $first = $service->variants()->where('code', 'TRIP-45-60')->firstOrFail();
        $second = $service->variants()->where('code', 'TRIP-60-PLUS')->firstOrFail();
        $pricing = app(CatalogPricingResolver::class);
        $this->assertSame(4500, $pricing->servicePrice($service, $first));
        $this->assertSame(6500, $pricing->servicePrice($service, $second));
        $snapshot = app(CatalogLineSnapshotFactory::class)->create($organization->id, 'service', $service->id, 1000, $first->id);
        $this->assertSame('TRIP:TRIP-45-60', $snapshot['catalog_code_snapshot']);
        $this->assertSame(4500, $snapshot['catalog_original_unit_price_cents']);
        $this->assertFalse($snapshot['catalog_taxable']);
        $this->assertSame(0, CatalogService::query()->forOrganization($other->id)->where('service_code', 'TRIP')->count());
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'catalog.bootstrap_service_created')->count());
        $this->assertSame(2, AuditEvent::query()->where('event_type', 'catalog.bootstrap_variant_created')->count());
    }

    public function test_trip_charge_bootstrap_is_idempotent_and_preserves_catalog_tax_and_price_customization(): void
    {
        $organization = Organization::factory()->create();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->ensureTripCharge($organization);
        $service = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'TRIP')->firstOrFail();
        $service->update(['taxable' => true]);
        CatalogServiceVariant::query()->where('catalog_service_id', $service->id)->where('code', 'TRIP-45-60')->update([
            'label' => 'Customized Dispatch Tier',
            'price_override_cents' => 5000,
        ]);

        $result = $bootstrap->ensureTripCharge($organization);

        $this->assertSame([], $result['created']);
        $this->assertSame(['TRIP', 'TRIP-45-60', 'TRIP-60-PLUS'], $result['unchanged']);
        $this->assertTrue($service->fresh()->taxable);
        $this->assertDatabaseHas('catalog_service_variants', [
            'catalog_service_id' => $service->id,
            'code' => 'TRIP-45-60',
            'label' => 'Customized Dispatch Tier',
            'price_override_cents' => 5000,
        ]);
        $this->assertSame(2, $service->variants()->count());
    }
}
