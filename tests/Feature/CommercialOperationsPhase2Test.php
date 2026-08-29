<?php

namespace Tests\Feature;

use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\QuoteWorkflow;
use App\Models\CatalogCategory;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CommercialDocument;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommercialOperationsPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-27 16:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatcher_creates_numbered_quote_with_revision_owned_dimensions(): void
    {
        [$organization,$dispatcher,$opportunity] = $this->context('dispatcher');
        $this->actingAs($dispatcher)->post(route('office.opportunities.quotes.store', $opportunity), ['title' => 'Technology upgrade'])->assertRedirect();
        $document = CommercialDocument::query()->sole();
        $revision = $document->revisions()->sole();
        $this->assertSame('Q-2026-0001', $document->document_number);
        $this->assertSame('Q-2026-0001-V1', $revision->displayNumber());
        $this->assertCount(1, $revision->locations);
        $this->assertCount(6, $revision->systems);
        $this->assertCount(6, $revision->phases);
        $this->assertDatabaseHas('document_sequences', ['organization_id' => $organization->id, 'document_type' => 'quote', 'current_value' => 1]);
        app(QuoteWorkflow::class)->addAllowance($revision, $dispatcher, [
            'content_version' => $revision->content_version,
            'description' => 'Site conditions',
            'amount_cents' => 5000,
        ]);
        $revision = $revision->fresh();
        $this->actingAs($dispatcher)->get(route('office.quotes.show', [$document, $revision]))
            ->assertOk()
            ->assertSee('Internal estimating workspace')
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('Add scope')
            ->assertSee('bulk-location')
            ->assertSee('bulk-system')
            ->assertSee('bulk-phase')
            ->assertSee('name="quantity"', false)
            ->assertSee('name="amount"', false)
            ->assertSee('name="tax_rate_percent"', false)
            ->assertDontSee('thousandths')
            ->assertDontSee('(cents)')
            ->assertDontSee('(bps)');
    }

    public function test_catalog_snapshot_calculation_discount_tax_option_and_unresolved_cost_are_deterministic(): void
    {
        [$organization,$admin,$opportunity] = $this->context('super_admin');
        [$product,$service] = $this->catalog($organization, $admin);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Estimate')->revisions()->sole();
        $productLine = app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->fresh()->content_version, 'catalog_item_type' => 'product', 'catalog_item_id' => $product->id, 'catalog_service_variant_id' => null, 'quantity_millis' => 2000, 'optional' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'service', 'catalog_item_id' => $service->id, 'catalog_service_variant_id' => null, 'quantity_millis' => 1000, 'optional' => false]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateLine($revision, $productLine, $admin, ['content_version' => $revision->content_version, 'description' => $productLine->description, 'customer_description' => $productLine->customer_description, 'quantity_millis' => 2000, 'pricing_mode' => 'direct', 'effective_unit_sell_cents' => 1001, 'pricing_value_basis_points' => 0, 'discount_type' => 'fixed', 'discount_value' => 1, 'optional' => false, 'included' => true, 'taxable' => true, 'location_id' => null, 'system_id' => null, 'phase_id' => null]);
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateRevision($revision, $admin, ['content_version' => $revision->content_version, 'discount_type' => 'percent', 'discount_value' => 1000, 'tax_rate_basis_points' => 825, 'tax_override_reason' => 'Approved test rate.']);
        $revision = $revision->fresh();
        $this->assertSame(4002, $revision->subtotal_cents);
        $this->assertSame(1, $revision->line_discount_total_cents);
        $this->assertSame(400, $revision->quote_discount_total_cents);
        $this->assertSame(149, $revision->tax_total_cents);
        $this->assertSame(3750, $revision->total_cents);
        $this->assertFalse($revision->cost_complete);
        $this->assertNull($revision->gross_margin_basis_points);
        $this->assertSame('SKU-1', $productLine->fresh()->source_code);
        $product->update(['name' => 'Changed later', 'default_sell_price_cents' => 99999]);
        $this->assertSame('Product one', $productLine->fresh()->description);
        $this->assertSame(1001, $productLine->fresh()->catalog_unit_sell_cents);
    }

    public function test_package_recipe_is_snapshotted_editable_in_draft_and_catalog_recipe_stays_unchanged(): void
    {
        [$organization,$admin,$opportunity] = $this->context('super_admin');
        [$product,$service,$unit,$category] = $this->catalog($organization, $admin);
        $package = CatalogPackage::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'package_code' => 'PKG-1', 'name' => 'Integrated package', 'pricing_model' => 'flat', 'default_price_cents' => 50000, 'taxable' => true, 'active' => true]);
        $source = $package->components()->create(['organization_id' => $organization->id, 'component_type' => 'product', 'catalog_product_id' => $product->id, 'component_uom_id' => $unit->id, 'quantity_basis' => 'direct', 'quantity_millis' => 2000, 'waste_basis_points' => 500, 'customer_visible' => false, 'active' => true]);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Package')->revisions()->sole();
        $line = app(QuoteWorkflow::class)->addCatalogLine($revision, $admin, ['content_version' => $revision->content_version, 'catalog_item_type' => 'package', 'catalog_item_id' => $package->id, 'catalog_service_variant_id' => null, 'quantity_millis' => 5000, 'optional' => false]);
        $component = $line->components()->sole();
        $revision = $revision->fresh();
        app(QuoteWorkflow::class)->updateComponent($revision, $line, $component, $admin, ['content_version' => $revision->content_version, 'name' => 'Tailored wire', 'quantity_millis' => 3500, 'waste_basis_points' => 750, 'customer_visible' => true]);
        $this->assertSame(3500, $component->fresh()->quantity_millis);
        $this->assertSame(2000, $source->fresh()->quantity_millis);
        $this->assertTrue($line->fresh()->cost_resolved);
    }

    public function test_quote_forms_convert_decimal_quantity_usd_and_percent_values_and_reject_extra_precision(): void
    {
        [$organization,$admin,$opportunity] = $this->context('super_admin');
        [$product] = $this->catalog($organization, $admin);
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Decimal inputs')->revisions()->sole();

        $this->actingAs($admin)->post(route('office.quotes.lines.catalog', [$revision->document, $revision]), [
            'content_version' => $revision->content_version,
            'catalog_selection' => 'product:'.$product->id,
            'quantity' => '1.125',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line = $revision->lines()->sole();
        $this->assertSame(1125, $line->quantity_millis);

        $revision = $revision->fresh();
        $this->actingAs($admin)->put(route('office.quotes.lines.update', [$revision->document, $revision, $line]), [
            'content_version' => $revision->content_version,
            'description' => $line->description,
            'customer_description' => $line->customer_description,
            'quantity' => '1.125',
            'pricing_mode' => 'direct',
            'effective_unit_sell' => '12.34',
            'pricing_value_percent' => '8.25',
            'discount_type' => 'percent',
            'discount_amount' => '8.25',
            'included' => '1',
            'taxable' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $line->refresh();
        $this->assertSame(1234, $line->effective_unit_sell_cents);
        $this->assertSame(825, $line->discount_value);

        $revision = $revision->fresh();
        $this->actingAs($admin)->put(route('office.quotes.lines.update', [$revision->document, $revision, $line]), [
            'content_version' => $revision->content_version,
            'description' => $line->description,
            'customer_description' => $line->customer_description,
            'quantity' => '1.125',
            'pricing_mode' => 'markup',
            'effective_unit_sell' => '12.34',
            'pricing_value_percent' => '8.25',
            'discount_type' => '',
            'discount_amount' => '',
            'included' => '1',
            'taxable' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(541, $line->fresh()->effective_unit_sell_cents);

        $revision = $revision->fresh();
        $this->actingAs($admin)->put(route('office.quotes.update', [$revision->document, $revision]), [
            'content_version' => $revision->content_version,
            'discount_type' => 'fixed',
            'discount_amount' => '12.34',
            'tax_rate_percent' => '8.25',
            'tax_override_reason' => 'Verified jurisdiction rate.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $revision->refresh();
        $this->assertSame(1234, $revision->discount_value);
        $this->assertSame(825, $revision->tax_rate_basis_points);

        $this->actingAs($admin)->post(route('office.quotes.lines.allowance', [$revision->document, $revision]), [
            'content_version' => $revision->content_version,
            'description' => 'Invalid precision',
            'amount' => '12.345',
        ])->assertSessionHasErrors('amount');
        $this->actingAs($admin)->post(route('office.quotes.lines.catalog', [$revision->document, $revision]), [
            'content_version' => $revision->content_version,
            'catalog_selection' => 'product:'.$product->id,
            'quantity' => '1.2345',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame(1, $revision->lines()->count());
    }

    public function test_draft_lock_clone_stale_rejection_and_historical_hash_stability(): void
    {
        [$organization,$admin,$opportunity] = $this->context('super_admin');
        $revision = app(QuoteWorkflow::class)->create($opportunity, $admin, 'Revision test')->revisions()->sole();
        $oldVersion = $revision->content_version;
        app(QuoteWorkflow::class)->addAllowance($revision, $admin, ['content_version' => $oldVersion, 'description' => 'Fixture allowance', 'amount_cents' => 10000, 'optional' => false, 'taxable' => false]);
        try {
            app(QuoteWorkflow::class)->addAllowance($revision, $admin, ['content_version' => $oldVersion, 'description' => 'Stale', 'amount_cents' => 1]);
            $this->fail('Stale mutation should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_version', $exception->errors());
        }
        $revision = $revision->fresh();
        $locked = app(QuoteWorkflow::class)->lockForHistory($revision, $admin, $revision->content_version);
        $lockedHash = $locked->content_hash;
        $draft = app(QuoteWorkflow::class)->cloneDraft($locked, $admin);
        $this->assertSame(2, $draft->version);
        $this->assertSame($locked->id, $draft->source_revision_id);
        $this->assertSame($lockedHash, $locked->fresh()->content_hash);
        $this->assertCount(1, $draft->lines);
        $this->expectException(ValidationException::class);
        app(QuoteWorkflow::class)->addAllowance($locked, $admin, ['content_version' => $locked->content_version, 'description' => 'Locked edit', 'amount_cents' => 1]);
    }

    public function test_quote_authorization_and_cross_organization_routes_are_isolated(): void
    {
        [$organization,$dispatcher,$opportunity] = $this->context('dispatcher');
        [$other,$otherAdmin] = $this->organizationMember('super_admin');
        $document = app(QuoteWorkflow::class)->create($opportunity, $dispatcher, 'Scoped');
        $revision = $document->revisions()->sole();
        $this->actingAs($otherAdmin)->get(route('office.quotes.show', [$document, $revision]))->assertNotFound();
        [$reviewer] = $this->member($organization, 'reviewer');
        $this->actingAs($reviewer)->get(route('office.quotes.show', [$document, $revision]))->assertForbidden();
        $this->actingAs($dispatcher)->get(route('office.quotes.show', [$document, $revision]))->assertOk()->assertDontSee('Internal cost and margin');
    }

    private function context(string $role): array
    {
        [$organization,$user] = $this->organizationMember($role);
        app(CommercialDefaults::class)->ensure($organization);
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'default_tax_rate_basis_points' => 0, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $stage = $organization->id ? OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole() : null;
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'owner_user_id' => $user->id, 'stage_id' => $stage->id, 'title' => 'Commercial opportunity', 'priority' => 'normal']);

        return [$organization, $user, $opportunity];
    }

    private function catalog(Organization $organization, User $actor): array
    {
        $unit = UnitOfMeasure::query()->create(['organization_id' => $organization->id, 'code' => 'each', 'name' => 'Each', 'dimension' => 'count', 'decimal_places' => 0, 'active' => true]);
        $category = CatalogCategory::query()->create(['organization_id' => $organization->id, 'code' => 'TEST', 'name' => 'Test', 'active' => true]);
        $product = CatalogProduct::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'base_uom_id' => $unit->id, 'default_sales_uom_id' => $unit->id, 'product_code' => 'SKU-1', 'name' => 'Product one', 'sales_quantity_millis' => 1000, 'default_cost_cents' => 500, 'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 1001, 'taxable' => true, 'active' => true]);
        $service = CatalogService::query()->create(['organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $unit->id, 'service_code' => 'SVC-1', 'name' => 'Service one', 'pricing_model' => 'flat', 'default_price_cents' => 2000, 'taxable' => false, 'active' => true]);

        return [$product, $service, $unit, $category];
    }

    private function organizationMember(string $role): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->member($organization, $role);

        return [$organization, $user];
    }

    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }
}
