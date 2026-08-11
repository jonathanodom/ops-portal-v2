<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\PackageDemandCalculator;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_can_create_a_flat_per_location_package_with_integer_price(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $location = $this->unit($organization, 'location');

        $this->actingAs($admin)->post('/office/catalog/packages', $this->packagePayload($location))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_packages', ['organization_id' => $organization->id, 'package_code' => 'ISH-TV-ROUGH', 'sales_uom_id' => $location->id, 'pricing_model' => 'flat', 'default_price_cents' => 249900, 'taxable' => true]);
        $metadata = AuditEvent::query()->where('event_type', 'catalog.package_created')->firstOrFail()->metadata;
        $this->assertArrayHasKey('changed_fields', $metadata);
        $this->assertStringNotContainsString('Integrated Smart Home TV Rough-In', json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2499.00', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_integrated_smart_home_tv_rough_in_quantity_five_calculates_required_standard_demand(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $foot = $this->unit($organization, 'foot');
        $package = $this->createPackage($organization, $this->unit($organization, 'location'));
        $recipe = [
            'CAT6-BLUE' => ['Blue Cat6', '2', 350000, 1750000],
            'CAT6-YELLOW' => ['Yellow Cat6', '2', 350000, 1750000],
            'SPK-16-2' => ['16/2 Speaker Wire', '1', 175000, 875000],
            'SPK-16-4' => ['16/4 Speaker Wire', '1', 175000, 875000],
        ];

        foreach ($recipe as $code => [$name, $pulls, $standardPerPackage]) {
            $product = $this->createProduct($organization, $foot, $code, $name);
            $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $product->id, '', [
                'quantity_basis' => 'pull_allowance',
                'basis_count' => $pulls,
                'basis_quantity' => '175',
            ]))->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('catalog_package_components', [
                'catalog_package_id' => $package->id,
                'catalog_product_id' => $product->id,
                'quantity_basis' => 'pull_allowance',
                'quantity_millis' => $standardPerPackage,
                'basis_count_millis' => (int) $pulls * 1000,
                'basis_quantity_millis' => 175000,
            ]);
        }

        $demand = app(PackageDemandCalculator::class)->calculate($package->fresh(), 5000);
        $byCode = $demand['products']->keyBy('product_code');
        foreach ($recipe as $code => [, , , $expectedMillis]) {
            $this->assertSame($expectedMillis, $byCode[$code]['standard_quantity_millis']);
            $this->assertSame($expectedMillis, $byCode[$code]['planning_quantity_millis']);
            $this->assertSame('Foot', $byCode[$code]['uom_name']);
        }
        $this->assertSame(5250000, $demand['products']->sum('standard_quantity_millis'));
        $this->assertSame('Integrated Smart Home TV Rough-In', $package->name);
        $this->assertSame('Location', $package->salesUom->name);
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    public function test_optional_product_waste_changes_planning_demand_without_changing_the_standard_recipe(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'), 'SPK-16-2', '16/2 Speaker Wire');
        $package = $this->createPackage($organization, $this->unit($organization, 'location'));
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $product->id, '175', ['waste_percent' => '5']))->assertRedirect()->assertSessionHasNoErrors();

        $component = $package->components()->firstOrFail();
        $this->assertSame(175000, $component->quantity_millis);
        $this->assertSame(500, $component->waste_basis_points);
        $row = app(PackageDemandCalculator::class)->calculate($package->fresh(), 5000)['products']->first();
        $this->assertSame(875000, $row['standard_quantity_millis']);
        $this->assertSame(918750, $row['planning_quantity_millis']);
        $this->assertSame(175000, $component->fresh()->quantity_millis);
    }

    public function test_service_components_scale_separately_and_inactive_components_leave_demand_without_deleting_recipe(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $visit = $this->unit($organization, 'visit');
        $service = $this->createService($organization, $visit);
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'), 'CAT6-BLUE', 'Blue Cat6');
        $package = $this->createPackage($organization, $this->unit($organization, 'location'));
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('service', $service->id, '', [
            'quantity_basis' => 'pull_allowance',
            'basis_count' => '1',
            'basis_quantity' => '1',
        ]))->assertSessionHasErrors('quantity_basis');
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('service', $service->id, '1'))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $product->id, '350'))->assertRedirect()->assertSessionHasNoErrors();
        $productComponent = $package->components()->where('component_type', 'product')->firstOrFail();

        $demand = app(PackageDemandCalculator::class)->calculate($package->fresh(), 5000);
        $this->assertSame(5000, $demand['services']->first()['standard_quantity_millis']);
        $this->assertSame(1750000, $demand['products']->first()['standard_quantity_millis']);
        $payload = $this->componentPayload('product', $product->id, '350');
        unset($payload['active']);
        $this->actingAs($admin)->put("/office/catalog/packages/{$package->id}/components/{$productComponent->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_package_components', ['id' => $productComponent->id, 'active' => false, 'quantity_millis' => 350000]);
        $this->assertTrue(app(PackageDemandCalculator::class)->calculate($package->fresh(), 5000)['products']->isEmpty());
    }

    public function test_component_selection_is_unique_and_organization_scoped(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [, , $other] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        app(CatalogDefaults::class)->ensureFor($other);
        $package = $this->createPackage($organization, $this->unit($organization, 'location'));
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'), 'CAT6-BLUE', 'Blue Cat6');
        $foreignProduct = $this->createProduct($other, $this->unit($other, 'foot'), 'FOREIGN', 'Foreign cable');
        $foreignPackage = $this->createPackage($other, $this->unit($other, 'location'), 'FOREIGN-PACKAGE');

        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $product->id, '350'))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $product->id, '175'))->assertSessionHasErrors('component_id');
        $this->actingAs($admin)->post("/office/catalog/packages/{$package->id}/components", $this->componentPayload('product', $foreignProduct->id, '350'))->assertSessionHasErrors('component_id');
        $this->actingAs($admin)->get("/office/catalog/packages/{$foreignPackage->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_package_roles_pricing_denial_and_deactivation_preserve_recipe(): void
    {
        [$admin, $membership, $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $location = $this->unit($organization, 'location');
        $package = $this->createPackage($organization, $location);
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'), 'CAT6-BLUE', 'Blue Cat6');
        $component = $package->components()->create(['organization_id' => $organization->id, 'component_type' => 'product', 'catalog_product_id' => $product->id, 'component_uom_id' => $product->base_uom_id, 'quantity_millis' => 350000, 'active' => true]);
        $pricing = Capability::query()->where('key', 'catalog.pricing.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($pricing, ['effect' => 'deny']);
        $this->actingAs($admin)->put("/office/catalog/packages/{$package->id}", $this->packagePayload($location, ['default_price' => '1.00']))->assertForbidden();
        $this->assertSame(249900, $package->fresh()->default_price_cents);

        $payload = $this->packagePayload($location);
        unset($payload['active'], $payload['pricing_model'], $payload['default_price'], $payload['taxable']);
        $this->actingAs($admin)->put("/office/catalog/packages/{$package->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($package->fresh()->active);
        $this->assertDatabaseHas('catalog_package_components', ['id' => $component->id]);

        [$reviewer] = $this->userWithRole('reviewer');
        $this->actingAs($reviewer)->get('/office/catalog/packages')->assertOk()->assertDontSee('Add package');
        $this->actingAs($reviewer)->post('/office/catalog/packages', [])->assertForbidden();
        [$dispatcher] = $this->userWithRole('dispatcher');
        $this->actingAs($dispatcher)->get('/office/catalog/packages')->assertOk()->assertDontSee('Add package');
        [$technician] = $this->userWithRole('technician');
        $this->actingAs($technician)->get('/office/catalog/packages')->assertForbidden();
    }

    public function test_package_ui_and_schema_preserve_checkpoint_three_boundaries(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $package = $this->createPackage($organization, $this->unit($organization, 'location'));

        $this->actingAs($admin)->get('/office/catalog/packages')->assertOk()->assertSee('data-office-width="workspace"', false)->assertSee('office-mobile-list')->assertSee('Packages');
        $this->actingAs($admin)->get("/office/catalog/packages/{$package->id}?quantity=5")->assertOk()->assertSee('data-office-width="detail"', false)->assertSee('Standard recipe')->assertSee('Pull count × allowance')->assertSee('Customer-facing transaction')->assertSee('5 × Integrated Smart Home TV Rough-In');
        $this->actingAs($admin)->get('/office/catalog/packages/create')->assertOk()->assertSee('data-office-width="form"', false)->assertSee('min-h-11', false);
        $this->assertTrue(Schema::hasColumns('catalog_package_components', ['quantity_basis', 'quantity_millis', 'basis_count_millis', 'basis_quantity_millis', 'waste_basis_points', 'component_uom_id']));
        $this->assertFalse(Schema::hasColumn('catalog_package_components', 'actual_quantity_millis'));
        $this->assertFalse(Schema::hasColumn('catalog_package_components', 'child_package_id'));
        $this->assertFalse(Schema::hasTable('inventory_balances'));

        $this->expectException(ValidationException::class);
        app(PackageDemandCalculator::class)->calculate($package, 0);
    }

    /** @return array{User, OrganizationMembership, Organization} */
    private function userWithRole(string $roleKey): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership, $organization];
    }

    private function unit(Organization $organization, string $code): UnitOfMeasure
    {
        return UnitOfMeasure::query()->forOrganization($organization->id)->where('code', $code)->firstOrFail();
    }

    private function createPackage(Organization $organization, UnitOfMeasure $unit, string $code = 'ISH-TV-ROUGH'): CatalogPackage
    {
        return CatalogPackage::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'package_code' => $code, 'name' => $code === 'FOREIGN-PACKAGE' ? 'Foreign Package' : 'Integrated Smart Home TV Rough-In', 'pricing_model' => 'flat', 'default_price_cents' => 249900, 'taxable' => true, 'active' => true]);
    }

    private function createProduct(Organization $organization, UnitOfMeasure $unit, string $code, string $name): CatalogProduct
    {
        return CatalogProduct::query()->create(['organization_id' => $organization->id, 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'product_code' => $code, 'name' => $name, 'sales_quantity_millis' => 1000, 'default_cost_quantity_millis' => 1000, 'taxable' => true, 'tracking_type' => 'lot_or_roll', 'active' => true]);
    }

    private function createService(Organization $organization, UnitOfMeasure $unit): CatalogService
    {
        return CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'ROUGH-IN-LABOR', 'name' => 'TV Rough-In Labor', 'pricing_model' => 'flat', 'default_price_cents' => 50000, 'active' => true]);
    }

    /** @return array<string, mixed> */
    private function packagePayload(UnitOfMeasure $unit, array $overrides = []): array
    {
        return $overrides + ['package_code' => 'ISH-TV-ROUGH', 'name' => 'Integrated Smart Home TV Rough-In', 'sales_uom_id' => $unit->id, 'customer_description' => 'Integrated cabling rough-in sold per location.', 'internal_description' => 'Standard 175-foot pull allowance.', 'pricing_model' => 'flat', 'default_price' => '2499.00', 'taxable' => '1', 'active' => '1'];
    }

    /** @return array<string, mixed> */
    private function componentPayload(string $type, int $id, string $quantity, array $overrides = []): array
    {
        return $overrides + ['component_type' => $type, 'component_id' => $id, 'quantity_basis' => 'direct', 'quantity' => $quantity, 'basis_count' => '', 'basis_quantity' => '', 'waste_percent' => '0', 'customer_visible' => '0', 'sort_order' => 0, 'internal_notes' => 'Standard recipe detail', 'active' => '1'];
    }
}
