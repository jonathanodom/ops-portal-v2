<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\CatalogService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase87ProductionCatalogBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_organization_creates_only_the_finalized_newday_catalog(): void
    {
        $organization = Organization::factory()->create();

        $result = app(NewDayCatalogBootstrap::class)->bootstrap($organization);

        $this->assertSame([
            'LABOR-RES-IT', 'LABOR-RES-TECH', 'LABOR-BUS', 'LABOR-PROJECT', 'LABOR-ENG',
            'TRIP', 'TRIP-45-60', 'TRIP-60-PLUS',
        ], $result['created']);
        $this->assertSame([], $result['unchanged']);
        $this->assertSame([], $result['conflicts']);
        $this->assertDatabaseCount('catalog_services', 6);
        $this->assertDatabaseCount('catalog_service_variants', 2);
        $this->assertDatabaseCount('catalog_products', 0);
        $this->assertDatabaseCount('catalog_packages', 0);
        $this->assertDatabaseHas('catalog_categories', ['organization_id' => $organization->id, 'code' => 'labor-services']);
        $this->assertDatabaseHas('catalog_categories', ['organization_id' => $organization->id, 'code' => 'service-dispatch']);
        $this->assertDatabaseHas('units_of_measure', ['organization_id' => $organization->id, 'code' => 'hour']);
        $this->assertDatabaseHas('units_of_measure', ['organization_id' => $organization->id, 'code' => 'visit']);

        foreach (NewDayCatalogBootstrap::LABOR_SERVICES as $definition) {
            $this->assertDatabaseHas('catalog_services', [
                'organization_id' => $organization->id,
                'service_code' => $definition['service_code'],
                'pricing_model' => 'hourly',
                'default_price_cents' => $definition['default_price_cents'],
                'active' => true,
            ]);
        }
    }

    public function test_repeated_bootstrap_is_idempotent_and_preserves_custom_prices_and_taxability(): void
    {
        $organization = Organization::factory()->create();
        $bootstrap = app(NewDayCatalogBootstrap::class);
        $bootstrap->bootstrap($organization);
        CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->update([
            'default_price_cents' => 14900,
            'taxable' => true,
        ]);
        $trip = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'TRIP')->firstOrFail();
        $trip->variants()->where('code', 'TRIP-45-60')->update(['price_override_cents' => 4900]);

        $second = $bootstrap->bootstrap($organization);

        $this->assertSame([], $second['created']);
        $this->assertCount(8, $second['unchanged']);
        $this->assertSame([], $second['conflicts']);
        $this->assertDatabaseHas('catalog_services', ['id' => $trip->id, 'service_code' => 'TRIP']);
        $this->assertDatabaseHas('catalog_services', ['organization_id' => $organization->id, 'service_code' => 'LABOR-BUS', 'default_price_cents' => 14900, 'taxable' => true]);
        $this->assertDatabaseHas('catalog_service_variants', ['catalog_service_id' => $trip->id, 'code' => 'TRIP-45-60', 'price_override_cents' => 4900]);
        $this->assertSame(6, AuditEvent::query()->where('event_type', 'catalog.bootstrap_service_created')->count());
        $this->assertSame(2, AuditEvent::query()->where('event_type', 'catalog.bootstrap_variant_created')->count());
    }

    public function test_structural_conflicts_are_reported_without_overwriting_existing_records(): void
    {
        $organization = Organization::factory()->create();
        app(CatalogDefaults::class)->ensureFor($organization);
        $visit = $organization->unitsOfMeasure()->where('code', 'visit')->firstOrFail();
        CatalogService::query()->create([
            'organization_id' => $organization->id,
            'sales_uom_id' => $visit->id,
            'service_code' => 'LABOR-BUS',
            'name' => 'Protected conflicting service',
            'pricing_model' => 'flat',
            'default_price_cents' => 50000,
            'active' => true,
        ]);

        $result = app(NewDayCatalogBootstrap::class)->bootstrap($organization);

        $this->assertContains('LABOR-BUS', $result['conflicts']);
        $this->assertDatabaseHas('catalog_services', ['organization_id' => $organization->id, 'service_code' => 'LABOR-BUS', 'pricing_model' => 'flat', 'default_price_cents' => 50000]);
        $this->assertSame(1, CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->count());
    }

    public function test_command_is_organization_scoped_reports_counts_and_fails_on_conflicts(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();

        $this->artisan('catalog:bootstrap-newday', ['--organization' => $first->id])
            ->expectsOutputToContain('Created: LABOR-RES-IT')
            ->assertSuccessful();
        $this->assertSame(6, CatalogService::query()->forOrganization($first->id)->count());
        $this->assertSame(0, CatalogService::query()->forOrganization($second->id)->count());

        $trip = CatalogService::query()->forOrganization($first->id)->where('service_code', 'TRIP')->firstOrFail();
        $trip->update(['active' => false]);
        $this->artisan('catalog:bootstrap-newday', ['--organization' => $first->id])
            ->expectsOutputToContain('Conflicts: TRIP')
            ->assertFailed();
        $this->assertFalse($trip->fresh()->active);
    }
}
