<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\LegacyLaborCatalogMigrator;
use App\Models\AuditEvent;
use App\Models\BillingLaborRate;
use App\Models\CatalogCategory;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase87LegacyLaborMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reuses_one_exact_catalog_match_and_preserves_legacy_records(): void
    {
        $organization = Organization::factory()->create();
        $rate = $this->legacyRate($organization, 'Business Service Labor', 13500);
        $service = $this->hourlyService($organization, 'CUSTOM-BUSINESS-LABOR', '  business   SERVICE labor ', 13500);

        $result = app(LegacyLaborCatalogMigrator::class)->migrate($organization);

        $this->assertSame('mapped_existing', $result['status']);
        $this->assertSame($service->id, $result['catalog_service_id']);
        $this->assertSame($service->id, $organization->billingSetting()->firstOrFail()->default_labor_catalog_service_id);
        $this->assertDatabaseHas('billing_labor_rates', [
            'id' => $rate->id,
            'hourly_rate_cents' => 13500,
            'is_default' => true,
            'active' => true,
        ]);
        $this->assertDatabaseCount('catalog_services', 1);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'billing.legacy_labor_mapped']);
    }

    public function test_it_creates_a_stable_compatibility_service_and_repeated_runs_are_safe(): void
    {
        $organization = Organization::factory()->create();
        $rate = $this->legacyRate($organization, 'Custom Service Rate', 12750);
        $other = BillingLaborRate::query()->create([
            'organization_id' => $organization->id,
            'name' => 'After Hours',
            'hourly_rate_cents' => 17500,
            'is_default' => false,
            'active' => true,
        ]);

        $first = app(LegacyLaborCatalogMigrator::class)->migrate($organization);
        $second = app(LegacyLaborCatalogMigrator::class)->migrate($organization);

        $this->assertSame('created_and_mapped', $first['status']);
        $this->assertSame('LEGACY-LABOR-'.$rate->id, $first['catalog_service_code']);
        $this->assertSame('already_configured', $second['status']);
        $this->assertCount(1, $first['warnings']);
        $this->assertStringContainsString("#{$other->id}", $first['warnings'][0]);
        $service = CatalogService::query()->where('service_code', 'LEGACY-LABOR-'.$rate->id)->firstOrFail();
        $this->assertSame('hourly', $service->pricing_model);
        $this->assertSame(12750, $service->default_price_cents);
        $this->assertSame('hour', $service->salesUom->code);
        $this->assertDatabaseCount('catalog_services', 1);
        $this->assertDatabaseCount('billing_labor_rates', 2);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'catalog.legacy_labor_service_created']);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'billing.legacy_labor_mapped')->count());
    }

    public function test_existing_catalog_default_is_never_replaced(): void
    {
        $organization = Organization::factory()->create();
        $this->legacyRate($organization, 'Legacy Standard', 10000);
        $configured = $this->hourlyService($organization, 'CURRENT-LABOR', 'Current Labor', 15000);
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_labor_catalog_service_id' => $configured->id,
        ]);

        $result = app(LegacyLaborCatalogMigrator::class)->migrate($organization);

        $this->assertSame('already_configured', $result['status']);
        $this->assertSame($configured->id, $organization->billingSetting()->firstOrFail()->default_labor_catalog_service_id);
        $this->assertDatabaseMissing('catalog_services', ['service_code' => 'LEGACY-LABOR-1']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'billing.legacy_labor_mapped']);
    }

    public function test_ambiguous_exact_matches_are_reported_without_writes(): void
    {
        $organization = Organization::factory()->create();
        $rate = $this->legacyRate($organization, 'Standard Labor', 12500);
        $this->hourlyService($organization, 'STANDARD-A', 'Standard Labor', 12500);
        $this->hourlyService($organization, 'STANDARD-B', ' standard labor ', 12500);

        $result = app(LegacyLaborCatalogMigrator::class)->migrate($organization);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame($rate->id, $result['legacy_rate_id']);
        $this->assertCount(1, $result['warnings']);
        $this->assertNull(OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_labor_catalog_service_id'));
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'billing.legacy_labor_mapped']);
    }

    public function test_conflicting_stable_code_is_not_overwritten(): void
    {
        $organization = Organization::factory()->create();
        $rate = $this->legacyRate($organization, 'Standard Labor', 12500);
        $service = $this->hourlyService($organization, 'LEGACY-LABOR-'.$rate->id, 'Custom protected service', 9999);

        $result = app(LegacyLaborCatalogMigrator::class)->migrate($organization);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(9999, $service->fresh()->default_price_cents);
        $this->assertNull(OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_labor_catalog_service_id'));
    }

    public function test_command_scopes_to_an_organization_and_returns_failure_for_conflicts(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $rate = $this->legacyRate($first, 'First Default', 11000);
        $this->legacyRate($second, 'Second Default', 12000);

        $this->artisan('billing:migrate-legacy-labor', ['--organization' => $first->id])
            ->expectsOutputToContain('created and mapped')
            ->assertSuccessful();
        $this->assertDatabaseHas('catalog_services', [
            'organization_id' => $first->id,
            'service_code' => 'LEGACY-LABOR-'.$rate->id,
        ]);
        $this->assertDatabaseMissing('organization_billing_settings', ['organization_id' => $second->id]);

        $conflict = Organization::factory()->create();
        $conflictRate = $this->legacyRate($conflict, 'Conflict', 10000);
        $this->hourlyService($conflict, 'LEGACY-LABOR-'.$conflictRate->id, 'Protected', 9999);
        $this->artisan('billing:migrate-legacy-labor', ['--organization' => $conflict->id])
            ->expectsOutputToContain('conflict')
            ->assertFailed();
    }

    private function legacyRate(Organization $organization, string $name, int $price): BillingLaborRate
    {
        return BillingLaborRate::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'hourly_rate_cents' => $price,
            'is_default' => true,
            'active' => true,
        ]);
    }

    private function hourlyService(Organization $organization, string $code, string $name, int $price): CatalogService
    {
        app(CatalogDefaults::class)->ensureFor($organization);
        $category = CatalogCategory::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'code' => 'labor-services'],
            ['name' => 'Labor Services', 'active' => true],
        );

        return CatalogService::query()->create([
            'organization_id' => $organization->id,
            'category_id' => $category->id,
            'sales_uom_id' => $organization->unitsOfMeasure()->where('code', 'hour')->value('id'),
            'service_code' => $code,
            'name' => $name,
            'pricing_model' => 'hourly',
            'default_price_cents' => $price,
            'taxable' => false,
            'customer_visible' => true,
            'active' => true,
        ]);
    }
}
