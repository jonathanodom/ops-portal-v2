<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\CatalogPricingResolver;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\CatalogCategory;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_default_units_are_organization_scoped_and_idempotent(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $defaults = app(CatalogDefaults::class);
        $defaults->ensureFor($first);
        UnitOfMeasure::query()->forOrganization($first->id)->where('code', 'foot')->update(['name' => 'Linear foot', 'active' => false]);
        $defaults->ensureFor($first);
        $defaults->ensureFor($second);

        $this->assertSame(10, UnitOfMeasure::query()->forOrganization($first->id)->count());
        $this->assertSame(10, UnitOfMeasure::query()->forOrganization($second->id)->count());
        $this->assertDatabaseHas('units_of_measure', ['organization_id' => $first->id, 'code' => 'foot', 'name' => 'Linear foot', 'active' => false, 'dimension' => 'length', 'decimal_places' => 2]);
        $this->assertDatabaseHas('units_of_measure', ['organization_id' => $first->id, 'code' => 'hour', 'decimal_places' => 3]);
    }

    public function test_super_admin_can_create_flat_hourly_and_recurring_services_with_integer_prices(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $category = CatalogCategory::query()->create(['organization_id' => $organization->id, 'code' => 'service-dispatch', 'name' => 'Service & Dispatch', 'active' => true]);
        $visit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'visit')->firstOrFail();
        $hour = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'hour')->firstOrFail();
        $month = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'month')->firstOrFail();

        $this->actingAs($admin)->post('/office/catalog/services', $this->servicePayload($category, $visit, ['service_code' => 'STARLINK-STD', 'name' => 'Starlink Standard Installation', 'default_price' => '1299.95']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/office/catalog/services', $this->servicePayload($category, $hour, ['service_code' => 'LABOR-ADV', 'name' => 'Advanced Programming Labor', 'pricing_model' => 'hourly', 'default_price' => '165.00']))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/office/catalog/services', $this->servicePayload($category, $month, ['service_code' => 'NDT-ADV', 'name' => 'NewDay Advantage', 'pricing_model' => 'recurring', 'default_price' => '49.99', 'billing_cadence' => 'month', 'billing_interval' => 1]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('catalog_services', ['organization_id' => $organization->id, 'service_code' => 'STARLINK-STD', 'default_price_cents' => 129995, 'taxable' => true]);
        $this->assertDatabaseHas('catalog_services', ['service_code' => 'LABOR-ADV', 'pricing_model' => 'hourly', 'default_price_cents' => 16500]);
        $this->assertDatabaseHas('catalog_services', ['service_code' => 'NDT-ADV', 'pricing_model' => 'recurring', 'default_price_cents' => 4999, 'billing_cadence' => 'month', 'billing_interval' => 1]);
        $metadata = AuditEvent::query()->where('event_type', 'catalog.service_created')->firstOrFail()->metadata;
        $this->assertArrayHasKey('changed_fields', $metadata);
        $this->assertStringNotContainsString('Starlink Standard Installation', json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('1299.95', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_tv_mounting_variants_resolve_explicit_prices_and_reject_cross_service_variants(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'each')->firstOrFail();
        $service = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'TV-MOUNT', 'name' => 'TV Mounting', 'pricing_model' => 'variant', 'taxable' => true, 'active' => true]);

        foreach ([['TV-55', 'Up to 55"', '299.00'], ['TV-75', '56"–75"', '399.00'], ['TV-76', '76"+', '549.00']] as [$code, $label, $price]) {
            $this->actingAs($admin)->post("/office/catalog/services/{$service->id}/variants", ['code' => $code, 'label' => $label, 'customer_label' => $label, 'price_override' => $price, 'sort_order' => 10, 'active' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $variant = $service->variants()->where('code', 'TV-75')->firstOrFail();
        $this->assertSame(39900, app(CatalogPricingResolver::class)->servicePrice($service->fresh(), $variant));

        $other = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'OTHER', 'name' => 'Other', 'pricing_model' => 'variant', 'default_price_cents' => 1000, 'active' => true]);
        $this->expectException(ValidationException::class);
        app(CatalogPricingResolver::class)->servicePrice($other, $variant);
    }

    public function test_pricing_resolver_supports_per_unit_variant_fallback_and_quote_required(): void
    {
        $organization = Organization::factory()->create();
        app(CatalogDefaults::class)->ensureFor($organization);
        $each = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'each')->firstOrFail();
        $perUnit = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $each->id, 'service_code' => 'DEVICE-CONFIG', 'name' => 'Device Configuration', 'pricing_model' => 'per_unit', 'default_price_cents' => 7500, 'active' => true]);
        $variantService = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $each->id, 'service_code' => 'VARIANT-FALLBACK', 'name' => 'Variant fallback', 'pricing_model' => 'variant', 'default_price_cents' => 20000, 'active' => true]);
        $fallbackVariant = CatalogServiceVariant::query()->create(['organization_id' => $organization->id, 'catalog_service_id' => $variantService->id, 'code' => 'STANDARD', 'label' => 'Standard', 'active' => true]);
        $quote = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $each->id, 'service_code' => 'CUSTOM', 'name' => 'Custom scope', 'pricing_model' => 'quote_required', 'active' => true]);
        $resolver = app(CatalogPricingResolver::class);

        $this->assertSame(7500, $resolver->servicePrice($perUnit));
        $this->assertSame(20000, $resolver->servicePrice($variantService, $fallbackVariant));
        $this->assertNull($resolver->servicePrice($quote));
    }

    public function test_category_nesting_is_limited_to_one_level_and_cross_organization_records_are_hidden(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        $root = CatalogCategory::query()->create(['organization_id' => $organization->id, 'code' => 'root', 'name' => 'Root', 'active' => true]);
        $child = CatalogCategory::query()->create(['organization_id' => $organization->id, 'parent_id' => $root->id, 'code' => 'child', 'name' => 'Child', 'active' => true]);
        $this->actingAs($admin)->post('/office/catalog/categories', ['name' => 'Grandchild', 'parent_id' => $child->id, 'sort_order' => 0, 'active' => '1'])->assertSessionHasErrors('parent_id');

        [, , $other] = $this->userWithRole('super_admin');
        $foreign = CatalogCategory::query()->create(['organization_id' => $other->id, 'code' => 'foreign', 'name' => 'Foreign', 'active' => true]);
        $this->actingAs($admin)->get("/office/catalog/categories/{$foreign->id}/edit?organization_id={$other->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'security.cross_organization_record_denied', 'subject_id' => $organization->id]);
    }

    public function test_role_defaults_and_explicit_pricing_denial_are_enforced_server_side(): void
    {
        [$admin, $membership, $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'visit')->firstOrFail();
        $service = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'LOCKED', 'name' => 'Protected service', 'pricing_model' => 'flat', 'default_price_cents' => 10000, 'active' => true]);
        $pricing = Capability::query()->where('key', 'catalog.pricing.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($pricing, ['effect' => 'deny']);
        $this->actingAs($admin)->put("/office/catalog/services/{$service->id}", $this->servicePayload(null, $unit, ['service_code' => 'LOCKED', 'name' => 'Forged price', 'default_price' => '1.00']))->assertForbidden();
        $this->assertSame(10000, $service->fresh()->default_price_cents);

        [$reviewer] = $this->userWithRole('reviewer');
        $this->actingAs($reviewer)->get('/office/catalog/services')->assertOk()->assertDontSee('Add service');
        $this->actingAs($reviewer)->post('/office/catalog/services', [])->assertForbidden();
        [$dispatcher] = $this->userWithRole('dispatcher');
        $this->actingAs($dispatcher)->get('/office/catalog/services')->assertOk()->assertDontSee('Add service');
        [$technician] = $this->userWithRole('technician');
        $this->actingAs($technician)->get('/office/catalog/services')->assertForbidden();
    }

    public function test_service_deactivation_preserves_variants_addons_and_ui_conventions(): void
    {
        [$admin, , $organization] = $this->userWithRole('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $unit = UnitOfMeasure::query()->forOrganization($organization->id)->where('code', 'visit')->firstOrFail();
        $service = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'PRIMARY', 'name' => 'Primary service', 'pricing_model' => 'variant', 'default_price_cents' => 10000, 'active' => true]);
        $variant = CatalogServiceVariant::query()->create(['organization_id' => $organization->id, 'catalog_service_id' => $service->id, 'code' => 'STANDARD', 'label' => 'Standard', 'price_override_cents' => 12500, 'active' => true]);
        $addon = CatalogService::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $unit->id, 'service_code' => 'ADDON', 'name' => 'Optional add-on', 'pricing_model' => 'flat', 'default_price_cents' => 2500, 'active' => true]);
        $service->addons()->attach($addon, ['organization_id' => $organization->id, 'sort_order' => 0]);

        $payload = $this->servicePayload(null, $unit, ['service_code' => 'PRIMARY', 'name' => 'Primary service', 'pricing_model' => 'variant', 'default_price' => '100.00']);
        unset($payload['active']);
        $this->actingAs($admin)->put("/office/catalog/services/{$service->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($service->fresh()->active);
        $this->assertDatabaseHas('catalog_service_variants', ['id' => $variant->id]);
        $this->assertDatabaseHas('catalog_service_addons', ['catalog_service_id' => $service->id, 'addon_service_id' => $addon->id]);

        $this->actingAs($admin)->get('/office/catalog/services')->assertOk()->assertSee('data-office-width="workspace"', false)->assertSee('Catalog')->assertSee('office-mobile-list');
        $this->actingAs($admin)->get("/office/catalog/services/{$service->id}")->assertOk()->assertSee('data-office-width="detail"', false)->assertSee('Variants')->assertSee('Optional add-ons');
        $this->actingAs($admin)->get('/office/catalog/services/create')->assertOk()->assertSee('data-office-width="form"', false)->assertSee('min-h-11', false);
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

    /** @return array<string, mixed> */
    private function servicePayload(?CatalogCategory $category, UnitOfMeasure $unit, array $overrides = []): array
    {
        return $overrides + [
            'service_code' => 'SERVICE-1', 'name' => 'Standard service', 'category_id' => $category?->id, 'sales_uom_id' => $unit->id,
            'customer_description' => 'Customer-safe description', 'internal_description' => 'Internal detail', 'pricing_model' => 'flat',
            'default_price' => '100.00', 'taxable' => '1', 'estimated_duration_minutes' => 60, 'customer_visible' => '1', 'active' => '1',
        ];
    }
}
