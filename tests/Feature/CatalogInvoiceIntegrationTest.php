<?php

namespace Tests\Feature;

use App\Domain\CatalogDefaults;
use App\Domain\CatalogLineSnapshotFactory;
use App\Domain\CloseoutReviewWorkflow;
use App\Domain\InvoiceWorkflow;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPartProposal;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogInvoiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_billing_adds_service_variant_product_and_manual_lines_with_immutable_catalog_snapshots(): void
    {
        [$invoice, $billing, $organization] = $this->invoiceScenario('billing');
        app(CatalogDefaults::class)->ensureFor($organization);
        $each = $this->unit($organization, 'each');
        $service = CatalogService::query()->create([
            'organization_id' => $organization->id, 'sales_uom_id' => $each->id,
            'service_code' => 'TV-MOUNT', 'name' => 'TV Mounting', 'customer_description' => 'Professional TV mounting',
            'pricing_model' => 'variant', 'taxable' => false, 'customer_visible' => true, 'active' => true,
        ]);
        $variant = CatalogServiceVariant::query()->create([
            'organization_id' => $organization->id, 'catalog_service_id' => $service->id,
            'code' => '56-75', 'label' => '56–75 inch', 'customer_label' => '56–75 inch TV',
            'price_override_cents' => 27500, 'active' => true,
        ]);
        $product = CatalogProduct::query()->create([
            'organization_id' => $organization->id, 'base_uom_id' => $each->id, 'default_sales_uom_id' => $each->id,
            'product_code' => 'MOUNT-FIXED', 'name' => 'Fixed TV Mount', 'customer_description' => 'Fixed wall mount',
            'sales_quantity_millis' => 1000, 'default_sell_price_cents' => 8900, 'default_cost_quantity_millis' => 1000,
            'taxable' => true, 'tracking_type' => 'standard', 'active' => true,
        ]);

        $this->actingAs($billing)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Add Catalog item')->assertSee('Search Catalog')
            ->assertSee('h-[100dvh]', false)->assertSee('sm:h-[92vh]', false)
            ->assertSee('+ Add Manual Line');

        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/catalog-lines", [
            'catalog_item' => "service:{$service->id}", 'catalog_service_variant_id' => $variant->id, 'catalog_quantity' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/catalog-lines", [
            'catalog_item' => "product:{$product->id}", 'catalog_quantity' => '2',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/lines", [
            'line_type' => 'other', 'description' => 'Custom negotiated item', 'quantity' => '1', 'unit' => 'each',
            'unit_price' => '10.00', 'included' => '1', 'taxable' => '0', 'override_reason' => 'One-off request',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $serviceLine = $invoice->lines()->where('catalog_item_type', 'service')->firstOrFail();
        $this->assertSame($variant->id, $serviceLine->catalog_service_variant_id);
        $this->assertSame('TV-MOUNT:56-75', $serviceLine->catalog_code_snapshot);
        $this->assertSame('TV Mounting — 56–75 inch TV', $serviceLine->catalog_name_snapshot);
        $this->assertSame('each', $serviceLine->catalog_unit_code_snapshot);
        $this->assertSame($service->id, $serviceLine->catalog_service_id);
        $this->assertSame(27500, $serviceLine->catalog_original_unit_price_cents);
        $this->assertSame(27500, $serviceLine->unit_price_cents);
        $productLine = $invoice->lines()->where('catalog_item_type', 'product')->firstOrFail();
        $this->assertSame(2000, $productLine->quantity_millis);
        $this->assertSame(8900, $productLine->unit_price_cents);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'description' => 'Custom negotiated item', 'catalog_item_type' => null]);

        $service->update(['name' => 'Renamed TV Service']);
        $variant->update(['price_override_cents' => 99900]);
        $product->update(['default_sell_price_cents' => 50000]);
        $this->assertSame('TV Mounting — 56–75 inch TV', $serviceLine->fresh()->catalog_name_snapshot);
        $this->assertSame(27500, $serviceLine->fresh()->unit_price_cents);
        $this->assertSame($service->id, $serviceLine->fresh()->catalog_service_id);
        $this->assertSame(8900, $productLine->fresh()->unit_price_cents);
    }

    public function test_package_quantity_five_keeps_one_customer_line_and_snapshots_recipe_demand(): void
    {
        [$invoice, $admin, $organization] = $this->invoiceScenario('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $foot = $this->unit($organization, 'foot');
        $location = $this->unit($organization, 'location');
        $package = CatalogPackage::query()->create([
            'organization_id' => $organization->id, 'sales_uom_id' => $location->id,
            'package_code' => 'ISH-TV-ROUGH', 'name' => 'Integrated Smart Home TV Rough-In',
            'customer_description' => 'Integrated Smart Home TV Rough-In', 'pricing_model' => 'flat',
            'default_price_cents' => 249900, 'taxable' => true, 'active' => true,
        ]);
        foreach ([['CAT6-BLUE', 'Blue Cat6', 2], ['CAT6-YELLOW', 'Yellow Cat6', 2], ['SPK-16-2', '16/2', 1], ['SPK-16-4', '16/4', 1]] as [$code, $name, $pulls]) {
            $product = CatalogProduct::query()->create([
                'organization_id' => $organization->id, 'base_uom_id' => $foot->id, 'default_sales_uom_id' => $foot->id,
                'product_code' => $code, 'name' => $name, 'sales_quantity_millis' => 1000,
                'default_cost_quantity_millis' => 1000, 'taxable' => true, 'tracking_type' => 'lot_or_roll', 'active' => true,
            ]);
            $package->components()->create([
                'organization_id' => $organization->id, 'component_type' => 'product', 'catalog_product_id' => $product->id,
                'component_uom_id' => $foot->id, 'quantity_basis' => 'pull_allowance', 'quantity_millis' => $pulls * 175000,
                'basis_count_millis' => $pulls * 1000, 'basis_quantity_millis' => 175000, 'active' => true,
            ]);
        }

        $this->actingAs($admin)->post("/office/invoices/{$invoice->id}/catalog-lines", [
            'catalog_item' => "package:{$package->id}", 'catalog_quantity' => '5',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $line = $invoice->lines()->where('catalog_item_type', 'package')->firstOrFail();
        $this->assertSame('Integrated Smart Home TV Rough-In', $line->description);
        $this->assertSame(5000, $line->quantity_millis);
        $this->assertSame(249900, $line->unit_price_cents);
        $this->assertCount(4, $line->catalog_package_recipe_snapshot['recipe']);
        $demand = collect($line->catalog_package_recipe_snapshot['expected_product_demand'])->keyBy('product_code');
        $this->assertSame(1750000, $demand['CAT6-BLUE']['standard_quantity_millis']);
        $this->assertSame(1750000, $demand['CAT6-YELLOW']['standard_quantity_millis']);
        $this->assertSame(875000, $demand['SPK-16-2']['standard_quantity_millis']);
        $this->assertSame(875000, $demand['SPK-16-4']['standard_quantity_millis']);
        $this->assertDatabaseCount('invoice_lines', 1);

        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        $this->actingAs($admin)->get("/invoices/{$invoice->id}/present")
            ->assertOk()->assertSee('Integrated Smart Home TV Rough-In')->assertDontSee('CAT6-BLUE')->assertDontSee('SPK-16-2');
        $replacement = app(InvoiceWorkflow::class)->voidAndReissue($invoice, $admin, 'Reissue package invoice', (string) Str::uuid());
        $this->assertSame($line->catalog_package_recipe_snapshot, $replacement->lines()->firstOrFail()->catalog_package_recipe_snapshot);
    }

    public function test_field_technician_can_select_catalog_without_price_controls_and_snapshot_survives_removal_rules(): void
    {
        [$invoice, , $organization, $approvedVisit] = $this->invoiceScenario('super_admin');
        [$technician, $membership] = $this->userWithRole('technician', $organization);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $approvedVisit->service_ticket_id,
            'service_location_id' => $approvedVisit->service_location_id,
            'timezone' => $approvedVisit->timezone,
            'status' => 'assigned',
        ]);
        $visit->assignments()->create(['organization_id' => $organization->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        app(CatalogDefaults::class)->ensureFor($organization);
        $each = $this->unit($organization, 'each');
        $product = CatalogProduct::query()->create([
            'organization_id' => $organization->id, 'base_uom_id' => $each->id, 'default_sales_uom_id' => $each->id,
            'product_code' => 'AP-01', 'name' => 'Wireless Access Point', 'sales_quantity_millis' => 1000,
            'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 32500, 'taxable' => true,
            'tracking_type' => 'serialized', 'active' => true,
        ]);

        $this->actingAs($technician)->get("/field/visits/{$visit->id}")->assertOk()->assertSee('Add Catalog item')->assertSee('Search Catalog')->assertDontSee('$325.00');
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/catalog-items", [
            'catalog_item' => "product:{$product->id}", 'catalog_quantity' => '2', 'billing_treatment' => 'billable',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $proposal = VisitPartProposal::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame('product', $proposal->catalog_item_type);
        $this->assertSame(2000, $proposal->catalog_quantity_millis);
        $this->assertSame(32500, $proposal->catalog_unit_price_cents);
        $this->assertSame($technician->id, $proposal->catalog_selected_by_id);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'catalog.field_item_selected', 'actor_id' => $technician->id]);

        $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'catalog.use')->firstOrFail(), ['effect' => 'deny']);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/catalog-items", [
            'catalog_item' => "product:{$product->id}", 'catalog_quantity' => '1', 'billing_treatment' => 'billable',
        ])->assertForbidden();
    }

    public function test_catalog_price_override_is_invoice_only_reasoned_and_audited_without_reason_contents(): void
    {
        [$invoice, $billing, $organization] = $this->invoiceScenario('billing');
        app(CatalogDefaults::class)->ensureFor($organization);
        $each = $this->unit($organization, 'each');
        $product = CatalogProduct::query()->create([
            'organization_id' => $organization->id, 'base_uom_id' => $each->id, 'default_sales_uom_id' => $each->id,
            'product_code' => 'CAM-01', 'name' => 'Camera', 'sales_quantity_millis' => 1000,
            'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 20000, 'taxable' => true,
            'tracking_type' => 'serialized', 'active' => true,
        ]);
        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/catalog-lines", ['catalog_item' => "product:{$product->id}", 'catalog_quantity' => '1'])->assertRedirect();
        $line = $invoice->lines()->where('catalog_item_type', 'product')->firstOrFail();
        $payload = ['line_type' => 'part', 'description' => 'Camera', 'quantity' => '1', 'unit' => 'Each', 'unit_price' => '225.00', 'included' => '1', 'taxable' => '1', 'billing_treatment' => 'billable', 'override_reason' => 'Private negotiated pricing reason'];
        $this->actingAs($billing)->put("/office/invoices/{$invoice->id}/lines/{$line->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame(22500, $line->unit_price_cents);
        $this->assertSame(20000, $line->catalog_original_unit_price_cents);
        $this->assertSame('Private negotiated pricing reason', $line->override_reason);
        $audit = AuditEvent::query()->where('event_type', 'invoice.catalog_price_overridden')->firstOrFail();
        $this->assertSame(['unit_price_cents'], $audit->metadata['changed_fields']);
        $this->assertStringNotContainsString('Private negotiated pricing reason', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_approved_field_catalog_snapshot_flows_to_invoice_without_rereading_current_price(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        app(CatalogDefaults::class)->ensureFor($organization);
        $each = $this->unit($organization, 'each');
        $product = CatalogProduct::query()->create([
            'organization_id' => $organization->id, 'base_uom_id' => $each->id, 'default_sales_uom_id' => $each->id,
            'product_code' => 'AP-FIELD', 'name' => 'Field Selected Access Point', 'sales_quantity_millis' => 1000,
            'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 32500, 'taxable' => true,
            'tracking_type' => 'serialized', 'active' => true,
        ]);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-FIELD', 'title' => 'Field Catalog flow', 'priority' => 'normal', 'source' => 'internal', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'timezone' => $location->timezone, 'status' => 'approved']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $review = CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $admin->id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
        $snapshot = app(CatalogLineSnapshotFactory::class)->create($organization->id, 'product', $product->id, 2000);
        $proposal = $closeout->parts()->create($snapshot + ['organization_id' => $organization->id, 'visit_id' => $visit->id, 'proposed_by_id' => $admin->id, 'description' => $snapshot['catalog_name_snapshot'], 'quantity' => '2.00', 'unit' => 'Each', 'billing_treatment' => 'billable', 'catalog_selected_by_id' => $admin->id, 'catalog_selected_at' => now()]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready']);
        $product->update(['name' => 'Changed after field selection', 'default_sell_price_cents' => 99900]);

        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $line = $invoice->lines()->where('source_part_proposal_id', $proposal->id)->firstOrFail();
        $this->assertSame($review->id, $line->source_review_id);
        $this->assertSame('Field Selected Access Point', $line->catalog_name_snapshot);
        $this->assertSame(32500, $line->unit_price_cents);
        $this->assertSame(2000, $line->quantity_millis);
        $this->assertSame('product', $line->catalog_item_type);
    }

    public function test_catalog_selection_is_organization_scoped_and_inactive_sources_are_rejected(): void
    {
        [$invoice, $billing, $organization] = $this->invoiceScenario('billing');
        [, , $other] = $this->invoiceScenario('billing', 'OTHER');
        app(CatalogDefaults::class)->ensureFor($other);
        $each = $this->unit($other, 'each');
        $foreign = CatalogProduct::query()->create([
            'organization_id' => $other->id, 'base_uom_id' => $each->id, 'default_sales_uom_id' => $each->id,
            'product_code' => 'FOREIGN', 'name' => 'Foreign Product', 'sales_quantity_millis' => 1000,
            'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => 1000, 'taxable' => true,
            'tracking_type' => 'standard', 'active' => true,
        ]);
        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/catalog-lines", ['catalog_item' => "product:{$foreign->id}", 'catalog_quantity' => '1'])->assertSessionHasErrors('catalog_item_id');
        $foreign->update(['organization_id' => $organization->id, 'active' => false]);
        $this->actingAs($billing)->post("/office/invoices/{$invoice->id}/catalog-lines", ['catalog_item' => "product:{$foreign->id}", 'catalog_quantity' => '1'])->assertSessionHasErrors('catalog_item_id');
        $this->assertDatabaseCount('invoice_lines', 0);
    }

    public function test_returned_correction_version_copies_catalog_snapshot_without_rereading_source(): void
    {
        [, $admin, $organization, $visit] = $this->invoiceScenario('super_admin');
        app(CatalogDefaults::class)->ensureFor($organization);
        $location = $this->unit($organization, 'location');
        $package = CatalogPackage::query()->create(['organization_id' => $organization->id, 'sales_uom_id' => $location->id, 'package_code' => 'CORRECTION-PACKAGE', 'name' => 'Correction Package', 'pricing_model' => 'flat', 'default_price_cents' => 50000, 'taxable' => true, 'active' => true]);
        $closeout = $visit->currentCloseout;
        $snapshot = app(CatalogLineSnapshotFactory::class)->create($organization->id, 'package', $package->id, 2000);
        $source = $closeout->parts()->create($snapshot + ['organization_id' => $organization->id, 'visit_id' => $visit->id, 'proposed_by_id' => $admin->id, 'description' => 'Correction Package', 'quantity' => '2.00', 'unit' => 'Location', 'billing_treatment' => 'billable', 'catalog_selected_by_id' => $admin->id, 'catalog_selected_at' => now()]);
        $package->update(['name' => 'Renamed after selection', 'default_price_cents' => 99900]);

        app(CloseoutReviewWorkflow::class)->returnForCorrection($closeout, $admin, 'Correct selected work', (string) Str::uuid());
        $copied = $visit->fresh()->currentCloseout->parts()->where('source_proposal_id', $source->id)->firstOrFail();
        $this->assertSame('Correction Package', $copied->catalog_name_snapshot);
        $this->assertSame(50000, $copied->catalog_unit_price_cents);
        $this->assertEquals($source->catalog_package_recipe_snapshot, $copied->catalog_package_recipe_snapshot);
        $this->assertSame($source->catalog_selected_at->toISOString(), $copied->catalog_selected_at->toISOString());
    }

    /** @return array{Invoice, User, Organization, Visit} */
    private function invoiceScenario(string $role, string $suffix = 'BASE'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->userWithRole($role, $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => "NDT-ST-2026-{$suffix}-{$organization->id}", 'title' => 'Catalog integration', 'priority' => 'normal', 'source' => 'internal', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'timezone' => $location->timezone, 'status' => 'approved']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $user->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'handed_off']);
        $invoice = Invoice::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'service_ticket_id' => $ticket->id, 'billing_handoff_id' => $handoff->id, 'invoice_number' => "NDT-INV-2026-{$suffix}-{$organization->id}", 'status' => 'draft', 'currency' => 'USD', 'payment_terms' => 'due_on_receipt', 'billing_name' => $customer->display_name, 'tax_rate_basis_points' => 825, 'creation_token' => (string) Str::uuid(), 'created_by_id' => $user->id, 'updated_by_id' => $user->id]);
        $handoff->update(['current_invoice_id' => $invoice->id]);

        return [$invoice, $user, $organization, $visit];
    }

    /** @return array{User, OrganizationMembership} */
    private function userWithRole(string $role, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$user, $membership];
    }

    private function unit(Organization $organization, string $code): UnitOfMeasure
    {
        return UnitOfMeasure::query()->forOrganization($organization->id)->where('code', $code)->firstOrFail();
    }
}
