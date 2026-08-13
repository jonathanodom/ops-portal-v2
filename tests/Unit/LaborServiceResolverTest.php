<?php

namespace Tests\Unit;

use App\Domain\CatalogDefaults;
use App\Domain\LaborServiceResolver;
use App\Domain\NewDayCatalogBootstrap;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LaborServiceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_organization_default_hourly_catalog_service(): void
    {
        $organization = Organization::factory()->create();
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization);
        $service = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_labor_catalog_service_id' => $service->id,
        ]);

        $resolved = app(LaborServiceResolver::class)->resolve($organization->id);

        $this->assertTrue($resolved->is($service));
        $this->assertSame(13500, $resolved->default_price_cents);
        $this->assertSame('hour', $resolved->salesUom->code);
    }

    public function test_an_approved_override_takes_precedence_over_the_organization_default(): void
    {
        $organization = Organization::factory()->create();
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization);
        $default = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $override = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-ENG')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_labor_catalog_service_id' => $default->id,
        ]);

        $resolved = app(LaborServiceResolver::class)->resolve($organization->id, $override->id);

        $this->assertTrue($resolved->is($override));
        $this->assertSame(16500, $resolved->default_price_cents);
    }

    public function test_it_never_falls_back_to_a_foreign_organization_override(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization);
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($other);
        $default = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $foreign = CatalogService::query()->forOrganization($other->id)->where('service_code', 'LABOR-ENG')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_labor_catalog_service_id' => $default->id,
        ]);

        $this->assertValidationMessage(
            fn () => app(LaborServiceResolver::class)->resolve($organization->id, $foreign->id),
            'The approved labor override must use a Catalog Service from this Organization.',
        );
    }

    public function test_it_rejects_missing_inactive_unpriced_nonhourly_and_wrong_unit_services(): void
    {
        $organization = Organization::factory()->create();
        app(CatalogDefaults::class)->ensureFor($organization);
        $hour = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'hour')->firstOrFail();
        $visit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'visit')->firstOrFail();
        $cases = [
            ['code' => 'INACTIVE', 'active' => false, 'pricing_model' => 'hourly', 'price' => 10000, 'uom' => $hour, 'message' => 'must be active'],
            ['code' => 'FLAT', 'active' => true, 'pricing_model' => 'flat', 'price' => 10000, 'uom' => $hour, 'message' => 'must use hourly pricing'],
            ['code' => 'UNPRICED', 'active' => true, 'pricing_model' => 'hourly', 'price' => null, 'uom' => $hour, 'message' => 'requires a configured hourly price'],
            ['code' => 'WRONG-UOM', 'active' => true, 'pricing_model' => 'hourly', 'price' => 10000, 'uom' => $visit, 'message' => "must use the Organization's Hour unit"],
        ];

        $this->assertValidationMessage(
            fn () => app(LaborServiceResolver::class)->resolve($organization->id),
            'Configure a default hourly Catalog labor service in Billing Settings before creating labor charges.',
        );
        foreach ($cases as $case) {
            $service = CatalogService::query()->create([
                'organization_id' => $organization->id,
                'sales_uom_id' => $case['uom']->id,
                'service_code' => $case['code'],
                'name' => $case['code'],
                'pricing_model' => $case['pricing_model'],
                'default_price_cents' => $case['price'],
                'active' => $case['active'],
            ]);
            $this->assertValidationMessage(
                fn () => app(LaborServiceResolver::class)->resolve($organization->id, $service->id),
                $case['message'],
                true,
            );
        }
    }

    private function assertValidationMessage(callable $action, string $message, bool $contains = false): void
    {
        try {
            $action();
            $this->fail('Expected labor-service validation to fail.');
        } catch (ValidationException $exception) {
            $actual = $exception->errors()['labor_service'][0];
            if ($contains) {
                $this->assertStringContainsString($message, $actual);
            } else {
                $this->assertSame($message, $actual);
            }
        }
    }
}
