<?php

namespace Tests\Feature;

use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\QuoteWorkflow;
use App\Models\CatalogCategory;
use App\Models\CatalogLaborRole;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercialOperationsPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_hourly_and_fixed_services_snapshot_approved_cost_defaults_without_changing_locked_history(): void
    {
        [$organization, $admin, $opportunity, $unit, $category] = $this->context('super_admin');
        $role = CatalogLaborRole::query()->create(['organization_id' => $organization->id, 'code' => 'INSTALLER', 'name' => 'Installer', 'hourly_cost_cents' => 6000, 'active' => true]);
        $hourly = CatalogService::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'service_code' => 'SVC-HR', 'name' => 'Hourly labor', 'pricing_model' => 'hourly', 'default_price_cents' => 12000, 'default_labor_role_id' => $role->id, 'taxable' => false, 'active' => true]);
        $fixed = CatalogService::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'service_code' => 'SVC-FIX', 'name' => 'Fixed install', 'pricing_model' => 'flat', 'default_price_cents' => 20000, 'default_labor_role_id' => $role->id, 'estimated_duration_minutes' => 90, 'taxable' => false, 'active' => true]);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Labor estimate')->revisions()->sole();
        $hourlyLine = app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'service', 'catalog_item_id' => $hourly->id, 'quantity_millis' => 2000]);
        $revision = $revision->fresh();
        $fixedLine = app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'service', 'catalog_item_id' => $fixed->id, 'quantity_millis' => 1000]);
        $this->assertSame(6000, $hourlyLine->cost_basis_cents);
        $this->assertSame(9000, $fixedLine->cost_basis_cents);
        $this->assertSame('labor_role', $fixedLine->cost_source_type);
        $role->update(['hourly_cost_cents' => 7000]);
        app(QuoteWorkflow::class)->refreshServiceEstimatingDefaults($fixed->fresh(), $admin);
        $this->assertSame(10500, $fixedLine->fresh()->cost_basis_cents);
        $locked = app(QuoteWorkflow::class)->lockForHistory($revision->fresh(), $admin, $revision->fresh()->content_version);
        $role->update(['hourly_cost_cents' => 9000]);
        app(QuoteWorkflow::class)->refreshServiceEstimatingDefaults($fixed->fresh(), $admin);
        $this->assertSame(10500, $fixedLine->fresh()->cost_basis_cents);
        $this->assertSame('approved', $locked->fresh()->status);
    }

    public function test_component_sum_package_uses_editable_revision_snapshot_and_preserves_catalog_recipe(): void
    {
        [$organization, $admin, $opportunity, $unit, $category] = $this->context('super_admin');
        $product = CatalogProduct::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'product_code' => 'PART-1', 'name' => 'Part', 'sales_quantity_millis' => 1000, 'default_cost_cents' => 400, 'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 1000, 'taxable' => true, 'active' => true]);
        $package = CatalogPackage::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'package_code' => 'PKG-SUM', 'name' => 'Recipe package', 'pricing_model' => 'component_sum', 'default_price_cents' => null, 'taxable' => true, 'active' => true]);
        $source = $package->components()->create(['organization_id' => $organization->id, 'component_type' => 'product', 'catalog_product_id' => $product->id, 'component_uom_id' => $unit->id, 'quantity_basis' => 'direct', 'quantity_millis' => 2000, 'waste_basis_points' => 0, 'active' => true]);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Package estimate')->revisions()->sole();
        $line = app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'package', 'catalog_item_id' => $package->id, 'quantity_millis' => 3000]);
        $this->assertSame('component_sum', $line->package_pricing_mode);
        $this->assertSame(2000, $line->effective_unit_sell_cents);
        $this->assertSame(6000, $line->gross_sell_cents);
        $component = $line->components()->sole();
        app(QuoteWorkflow::class)->updateComponent($revision->fresh(), $line, $component, $admin, ['content_version' => $revision->fresh()->content_version, 'name' => 'Tailored Part', 'quantity_millis' => 3000, 'waste_basis_points' => 0, 'customer_visible' => true]);
        $this->assertSame(3000, $line->fresh()->effective_unit_sell_cents);
        $this->assertSame(9000, $line->fresh()->gross_sell_cents);
        $this->assertSame(2000, $source->fresh()->quantity_millis);
    }

    public function test_quote_overlay_requires_catalog_pricing_authority_and_atomically_creates_then_adds(): void
    {
        [$organization, $admin, $opportunity, $unit, $category] = $this->context('super_admin');
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Overlay')->revisions()->sole();
        $payload = ['content_version' => $revision->content_version, 'item_type' => 'service', 'item_code' => 'NEW-SVC', 'name' => 'New service', 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'default_price' => '125.00', 'default_internal_cost' => '50.00', 'quantity' => '1', 'taxable' => 1];
        $this->actingAs($admin)->post(route('office.quotes.catalog-items.store', [$revision->document, $revision]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $service = CatalogService::query()->where('service_code', 'NEW-SVC')->sole();
        $this->assertDatabaseHas('commercial_revision_lines', ['commercial_revision_id' => $revision->id, 'catalog_service_id' => $service->id, 'cost_basis_cents' => 5000]);

        [$dispatcher] = $this->member($organization, 'dispatcher');
        $revision = $revision->fresh();
        $this->actingAs($dispatcher)->post(route('office.quotes.catalog-items.store', [$revision->document, $revision]), $payload + ['content_version' => $revision->content_version, 'item_code' => 'DENIED'])->assertForbidden();
        $this->assertDatabaseMissing('catalog_services', ['organization_id' => $organization->id, 'service_code' => 'DENIED']);

        $this->actingAs($admin)->post(route('office.quotes.catalog-items.store', [$revision->document, $revision]), array_merge($payload, ['content_version' => 1, 'item_code' => 'ROLLBACK']))->assertSessionHasErrors('content_version');
        $this->assertDatabaseMissing('catalog_services', ['organization_id' => $organization->id, 'service_code' => 'ROLLBACK']);
    }

    private function context(string $role): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->member($organization, $role);
        app(CommercialDefaults::class)->ensure($organization);
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'default_tax_rate_basis_points' => 0, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole();
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'owner_user_id' => $user->id, 'stage_id' => $stage->id, 'title' => 'Commercial opportunity', 'priority' => 'normal']);
        $unit = UnitOfMeasure::query()->create(['organization_id' => $organization->id, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'decimal_places' => 0, 'active' => true]);
        $category = CatalogCategory::query()->create(['organization_id' => $organization->id, 'code' => 'TEST', 'name' => 'Test', 'active' => true]);

        return [$organization, $user, $opportunity, $unit, $category];
    }

    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }
}
