<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\CatalogProductConversion;
use App\Models\Capability;
use App\Models\CatalogProduct;
use App\Models\CatalogProductPurchaseUnit;
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

class CatalogProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_product_uses_integer_money_and_fixed_point_base_and_sales_units(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $foot = $this->unit($organization, 'foot');

        $this->actingAs($admin)->post('/office/catalog/products', $this->productPayload($foot, [
            'product_code' => 'CAT6-BLUE',
            'name' => 'Blue Cat6 Cable',
            'manufacturer' => 'WireWorks',
            'model' => 'CAT6-550-BLU',
            'sales_quantity' => '1',
            'default_cost' => '187.49',
            'default_cost_quantity' => '500',
            'default_sell_price' => '0.95',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_products', [
            'organization_id' => $organization->id,
            'product_code' => 'CAT6-BLUE',
            'base_uom_id' => $foot->id,
            'sales_quantity_millis' => 1000,
            'default_cost_cents' => 18749,
            'default_cost_quantity_millis' => 500000,
            'default_sell_price_cents' => 95,
        ]);
    }

    public function test_create_form_enables_sku_code_autofill_but_edit_form_does_not(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');
        $product = $this->createProduct($organization, $unit);

        $this->actingAs($admin)->get('/office/catalog/products/create')
            ->assertOk()
            ->assertSee('data-product-code-autofill', false)
            ->assertSee("sku.addEventListener('input', synchronize)", false);
        $this->actingAs($admin)->get("/office/catalog/products/{$product->id}/edit")
            ->assertOk()
            ->assertDontSee('data-product-code-autofill', false);
    }

    public function test_blank_product_code_defaults_to_sku_on_create(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');

        $this->actingAs($admin)->post('/office/catalog/products', $this->productPayload($unit, [
            'product_code' => '',
            'sku' => 'u7-outdoor',
            'name' => 'Outdoor Access Point',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_products', [
            'organization_id' => $organization->id,
            'product_code' => 'U7-OUTDOOR',
            'sku' => 'u7-outdoor',
        ]);
    }

    public function test_blank_code_and_sku_default_to_manufacturer_and_model(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');

        $this->actingAs($admin)->post('/office/catalog/products', $this->productPayload($unit, [
            'product_code' => '',
            'sku' => '',
            'manufacturer' => 'ProView',
            'model' => 'PROB-8SEKL28AD-S',
            'name' => '8MP Bullet Camera',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_products', [
            'organization_id' => $organization->id,
            'product_code' => 'PROVIEW-PROB-8SEKL28AD-S',
        ]);
    }

    public function test_manual_product_code_override_is_preserved(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');

        $this->actingAs($admin)->post('/office/catalog/products', $this->productPayload($unit, [
            'product_code' => 'ndt-cam-8mp-bullet',
            'sku' => 'PROB-8SEKL28AD-S',
            'name' => 'Private Label Camera',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_products', [
            'organization_id' => $organization->id,
            'product_code' => 'NDT-CAM-8MP-BULLET',
            'sku' => 'PROB-8SEKL28AD-S',
        ]);
    }

    public function test_generated_product_code_still_enforces_organization_uniqueness(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');
        $this->createProduct($organization, $unit, 'U7-OUTDOOR');

        $this->actingAs($admin)->post('/office/catalog/products', $this->productPayload($unit, [
            'product_code' => '',
            'sku' => 'U7-OUTDOOR',
            'name' => 'Duplicate Outdoor Access Point',
        ]))->assertSessionHasErrors('product_code');

        $this->assertSame(1, CatalogProduct::query()->forOrganization($organization->id)->where('product_code', 'U7-OUTDOOR')->count());
    }

    public function test_changing_sku_on_edit_does_not_rewrite_existing_product_code(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = $this->unit($organization, 'each');
        $product = $this->createProduct($organization, $unit, 'NDT-CAM-8MP-BULLET');

        $this->actingAs($admin)->put("/office/catalog/products/{$product->id}", $this->productPayload($unit, [
            'product_code' => 'NDT-CAM-8MP-BULLET',
            'sku' => 'NEW-OEM-SKU',
            'name' => $product->name,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('NDT-CAM-8MP-BULLET', $product->product_code);
        $this->assertSame('NEW-OEM-SKU', $product->sku);
    }

    public function test_wire_purchase_units_convert_250_500_and_1000_foot_boxes_exactly(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $foot = $this->unit($organization, 'foot');
        $box = $this->unit($organization, 'box');
        $product = $this->createProduct($organization, $foot);

        foreach ([250, 500, 1000] as $size) {
            $this->actingAs($admin)->post("/office/catalog/products/{$product->id}/purchase-units", [
                'purchase_uom_id' => $box->id,
                'label' => "{$size} ft box",
                'base_quantity' => (string) $size,
                'default_purchase_cost' => '100.00',
                'active' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $resolver = app(CatalogProductConversion::class);
        foreach ([250, 500, 1000] as $size) {
            $option = CatalogProductPurchaseUnit::query()->where('catalog_product_id', $product->id)->where('label', "{$size} ft box")->firstOrFail();
            $this->assertSame($size * 1000, $option->base_quantity_millis);
            $this->assertSame($size * 1000, $resolver->purchaseQuantityToBaseMillis($option, 1000));
            $this->assertSame($size * 2500, $resolver->purchaseQuantityToBaseMillis($option, 2500));
        }

        $this->expectException(ValidationException::class);
        $resolver->purchaseQuantityToBaseMillis($option, PHP_INT_MAX);
    }

    public function test_setting_a_default_purchase_unit_is_transactional_and_keeps_at_most_one_active_default(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'));
        $box = $this->unit($organization, 'box');

        foreach ([250, 500] as $size) {
            $this->actingAs($admin)->post("/office/catalog/products/{$product->id}/purchase-units", ['purchase_uom_id' => $box->id, 'label' => "{$size} ft box", 'base_quantity' => $size, 'is_default' => '1', 'active' => '1'])->assertRedirect();
        }

        $this->assertSame(1, $product->purchaseUnits()->where('active', true)->where('is_default', true)->count());
        $this->assertDatabaseHas('catalog_product_purchase_units', ['catalog_product_id' => $product->id, 'label' => '500 ft box', 'is_default' => true]);
    }

    public function test_cross_organization_products_units_and_nested_purchase_options_are_not_accessible(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        [, , $other] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        app(CatalogDefaults::class)->ensureFor($other);
        $product = $this->createProduct($organization, $this->unit($organization, 'foot'));
        $foreignProduct = $this->createProduct($other, $this->unit($other, 'foot'), 'FOREIGN');
        $foreignBox = $this->unit($other, 'box');
        $foreignOption = CatalogProductPurchaseUnit::query()->create(['organization_id' => $other->id, 'catalog_product_id' => $foreignProduct->id, 'purchase_uom_id' => $foreignBox->id, 'label' => 'Foreign box', 'base_quantity_millis' => 250000, 'active' => true]);

        $this->actingAs($admin)->get("/office/catalog/products/{$foreignProduct->id}")->assertNotFound();
        $this->actingAs($admin)->post("/office/catalog/products/{$product->id}/purchase-units", ['purchase_uom_id' => $foreignBox->id, 'label' => 'Forged', 'base_quantity' => 250, 'active' => '1'])->assertSessionHasErrors('purchase_uom_id');
        $this->actingAs($admin)->put("/office/catalog/products/{$product->id}/purchase-units/{$foreignOption->id}", [])->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_catalog_roles_and_explicit_pricing_denial_are_enforced(): void
    {
        [$admin, $membership, $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $product = $this->createProduct($organization, $this->unit($organization, 'each'));
        $pricing = Capability::query()->where('key', 'catalog.pricing.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($pricing, ['effect' => 'deny']);
        $this->actingAs($admin)->put("/office/catalog/products/{$product->id}", $this->productPayload($this->unit($organization, 'each'), ['default_sell_price' => '1.00']))->assertForbidden();
        $this->assertSame(1500, $product->fresh()->default_sell_price_cents);

        [$reviewer] = $this->userWithRole('reviewer');
        $this->actingAs($reviewer)->get('/office/catalog/products')->assertOk()->assertDontSee('Add product');
        $this->actingAs($reviewer)->post('/office/catalog/products', [])->assertForbidden();
        [$dispatcher] = $this->userWithRole('dispatcher');
        $this->actingAs($dispatcher)->get('/office/catalog/products')->assertOk()->assertDontSee('Add product');
        [$technician] = $this->userWithRole('technician');
        $this->actingAs($technician)->get('/office/catalog/products')->assertForbidden();
    }

    public function test_deactivation_preserves_purchase_conversions_and_product_ui_conventions(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $foot = $this->unit($organization, 'foot');
        $product = $this->createProduct($organization, $foot);
        $option = $product->purchaseUnits()->create(['organization_id' => $organization->id, 'purchase_uom_id' => $this->unit($organization, 'box')->id, 'label' => '250 ft box', 'base_quantity_millis' => 250000, 'active' => true]);
        $payload = $this->productPayload($foot);
        unset($payload['active']);
        $this->actingAs($admin)->put("/office/catalog/products/{$product->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->active);
        $this->assertDatabaseHas('catalog_product_purchase_units', ['id' => $option->id, 'base_quantity_millis' => 250000]);
        $this->actingAs($admin)->get('/office/catalog/products')->assertOk()->assertSee('data-office-width="workspace"', false)->assertSee('office-mobile-list')->assertSee('Products');
        $this->actingAs($admin)->get("/office/catalog/products/{$product->id}")->assertOk()->assertSee('data-office-width="detail"', false)->assertSee('Purchase units')->assertSee('no balance exists');
        $this->actingAs($admin)->get('/office/catalog/products/create')->assertOk()->assertSee('data-office-width="form"', false)->assertSee('min-h-11', false);
    }

    public function test_checkpoint_two_schema_has_no_inventory_balance_or_stock_transaction_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_products', ['base_uom_id', 'default_sales_uom_id', 'sales_quantity_millis', 'default_cost_cents', 'default_sell_price_cents']));
        $this->assertTrue(Schema::hasColumns('catalog_product_purchase_units', ['purchase_uom_id', 'base_quantity_millis', 'default_purchase_cost_cents']));
        $this->assertFalse(Schema::hasTable('inventory_balances'));
        $this->assertFalse(Schema::hasTable('inventory_transactions'));
        $this->assertFalse(Schema::hasTable('stock_movements'));
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

    private function createProduct(Organization $organization, UnitOfMeasure $unit, string $code = 'CAT6-BLUE'): CatalogProduct
    {
        return CatalogProduct::query()->create(['organization_id' => $organization->id, 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'product_code' => $code, 'name' => $code === 'FOREIGN' ? 'Foreign product' : 'Blue Cat6 Cable', 'sales_quantity_millis' => 1000, 'default_cost_cents' => 10000, 'default_cost_quantity_millis' => 250000, 'default_sell_price_cents' => 1500, 'taxable' => true, 'tracking_type' => 'lot_or_roll', 'active' => true]);
    }

    /** @return array<string, mixed> */
    private function productPayload(UnitOfMeasure $unit, array $overrides = []): array
    {
        return $overrides + ['product_code' => 'CAT6-BLUE', 'sku' => 'CAT6-250-BLU', 'name' => 'Blue Cat6 Cable', 'manufacturer' => 'WireWorks', 'model' => 'CAT6', 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'sales_quantity' => '1', 'customer_description' => 'Blue category cable', 'internal_description' => 'Copper cable', 'tracking_type' => 'lot_or_roll', 'default_cost' => '100.00', 'default_cost_quantity' => '250', 'default_sell_price' => '15.00', 'taxable' => '1', 'active' => '1'];
    }
}
