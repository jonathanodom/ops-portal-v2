<?php

namespace Tests\Feature;

use App\Domain\CatalogLineSnapshotFactory;
use App\Domain\InvoiceCalculator;
use App\Domain\InvoiceWorkflow;
use App\Domain\NewDayCatalogBootstrap;
use App\Jobs\DeleteUnusedOrganizationBrandAsset;
use App\Jobs\RenderInvoicePdf;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\BillingLaborRate;
use App\Models\Capability;
use App\Models\CatalogService;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationBrandAsset;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\IncidentRecorder;
use App\Support\InvoiceNumber;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase6InvoicingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_handoff_builds_one_ticket_wide_invoice_with_rounded_visit_labor_and_provenance(): void
    {
        [$organization, $admin, $handoff, $first, $second] = $this->billingScenario();
        $workflow = app(InvoiceWorkflow::class);
        $token = (string) Str::uuid();
        $invoice = $workflow->createFromHandoff($handoff, $admin, $token);
        $retry = $workflow->createFromHandoff($handoff->fresh(), $admin, (string) Str::uuid());

        $this->assertSame($invoice->id, $retry->id);
        $this->assertMatchesRegularExpression('/^NDT-INV-\d{4}-0001$/', $invoice->invoice_number);
        $this->assertSame('handed_off', $handoff->fresh()->status);
        $this->assertSame($invoice->id, $handoff->fresh()->current_invoice_id);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_closeouts', 2);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $first->id, 'line_type' => 'labor', 'quantity_millis' => 1250, 'unit_price_cents' => 13500, 'labor_rate_id' => null, 'catalog_service_id' => $organization->billingSetting->default_labor_catalog_service_id, 'catalog_code_snapshot' => 'LABOR-BUS']);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $second->id, 'line_type' => 'labor', 'quantity_millis' => 500, 'unit_price_cents' => 13500, 'labor_rate_id' => null, 'catalog_service_id' => $organization->billingSetting->default_labor_catalog_service_id, 'catalog_code_snapshot' => 'LABOR-BUS']);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_part_proposal_id' => VisitPartProposal::query()->value('id'), 'unit_price_cents' => null]);
        $this->assertSame(23625, $invoice->fresh()->subtotal_cents);
        $this->assertSame($organization->id, $invoice->organization_id);
        foreach ($invoice->lines->where('line_type', 'labor') as $line) {
            $this->assertSame('Service Labor — Multi-visit repair', $line->description);
            $this->assertStringNotContainsString('Visit #', $line->description);
            $this->assertSame('Business Service Labor', $line->catalog_name_snapshot);
            $this->assertSame('Hour', $line->catalog_unit_name_snapshot);
            $this->assertSame(13500, $line->catalog_original_unit_price_cents);
            $this->assertSame($line->source_closeout_id, $invoice->closeoutLinks->firstWhere('visit_id', $line->source_visit_id)->closeout_id);
        }
        $labor = CatalogService::query()->findOrFail($organization->billingSetting->default_labor_catalog_service_id);
        $labor->update(['name' => 'Changed after invoice creation', 'default_price_cents' => 99900, 'taxable' => true]);
        foreach ($invoice->fresh()->lines->where('line_type', 'labor') as $line) {
            $this->assertSame('Business Service Labor', $line->catalog_name_snapshot);
            $this->assertSame(13500, $line->unit_price_cents);
            $this->assertSame(13500, $line->catalog_original_unit_price_cents);
            $this->assertFalse($line->catalog_taxable);
        }
    }

    public function test_automatic_labor_uses_the_configured_rounding_and_minimum_policy(): void
    {
        [, $admin, $handoff, $first, $second] = $this->billingScenario(false);
        OrganizationBillingSetting::query()->where('organization_id', $handoff->organization_id)->update([
            'labor_billing_increment_minutes' => 30,
            'labor_rounding_rule' => 'nearest',
            'minimum_billable_minutes' => 60,
        ]);

        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'source_visit_id' => $first->id,
            'quantity_millis' => 1000,
            'catalog_quantity_millis' => 1000,
        ]);
        $this->assertDatabaseHas('invoice_lines', [
            'invoice_id' => $invoice->id,
            'source_visit_id' => $second->id,
            'quantity_millis' => 1000,
            'catalog_quantity_millis' => 1000,
        ]);
    }

    public function test_catalog_backed_labor_cannot_be_relinked_to_a_legacy_named_rate(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $line = $invoice->lines()->where('line_type', 'labor')->firstOrFail();
        $legacy = BillingLaborRate::query()->create([
            'organization_id' => $invoice->organization_id,
            'name' => 'Historical rate',
            'hourly_rate_cents' => 5000,
            'active' => true,
        ]);

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertDontSee('Named labor rate');
        $this->actingAs($admin)->put("/office/invoices/{$invoice->id}/lines/{$line->id}", [
            'line_type' => 'labor',
            'description' => $line->description,
            'quantity' => '1.25',
            'unit' => 'Hour',
            'unit_price' => '50.00',
            'included' => '1',
            'labor_rate_id' => $legacy->id,
            'override_reason' => 'Forged legacy selection',
        ])->assertSessionHasErrors('labor_rate_id');
        $this->assertNull($line->fresh()->labor_rate_id);
        $this->assertSame(13500, $line->fresh()->unit_price_cents);
    }

    public function test_ready_refreshes_exact_legacy_labor_description_and_blocks_manual_database_ids(): void
    {
        [, $admin, $handoff, $first] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $line = $invoice->lines()->where('source_visit_id', $first->id)->firstOrFail();
        $line->update(['description' => "Visit #{$first->id} — {$invoice->serviceTicket->ticket_number}: {$invoice->serviceTicket->title}"]);

        $workflow->markReady($invoice, $admin);

        $this->assertSame('Service Labor — Multi-visit repair', $line->fresh()->description);

        $invoice->update(['status' => 'draft']);
        $line->update(['description' => "Technician notes from Visit #{$first->id}"]);
        $this->expectException(ValidationException::class);
        $workflow->markReady($invoice, $admin);
    }

    public function test_invoice_numbers_are_organization_year_scoped_expand_and_roll_back_safely(): void
    {
        $first = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $second = Organization::factory()->create(['timezone' => 'America/Chicago']);
        Carbon::setTestNow('2026-08-08 12:00:00');
        $numbers = app(InvoiceNumber::class);
        $this->assertSame('NDT-INV-2026-0001', $numbers->next($first));
        $this->assertSame('NDT-INV-2026-0001', $numbers->next($second));
        DocumentSequence::query()->where('organization_id', $first->id)->where('document_type', 'invoice')->update(['current_value' => 9999]);
        $this->assertSame('NDT-INV-2026-10000', $numbers->next($first));
        try {
            DB::transaction(function () use ($numbers, $second): void {
                $numbers->next($second);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // Expected rollback.
        }
        $this->assertSame(1, DocumentSequence::query()->where('organization_id', $second->id)->where('document_type', 'invoice')->value('current_value'));
        Carbon::setTestNow();
    }

    public function test_calculator_allocates_discount_and_tax_deterministically(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $invoice->lines()->delete();
        $invoice->update(['discount_type' => 'fixed', 'discount_value' => 100, 'tax_rate_basis_points' => 825]);
        $invoice->lines()->create(['organization_id' => $invoice->organization_id, 'line_type' => 'other', 'description' => 'A', 'quantity_millis' => 1000, 'unit_price_cents' => 1001, 'included' => true, 'taxable' => true, 'sort_order' => 10]);
        $invoice->lines()->create(['organization_id' => $invoice->organization_id, 'line_type' => 'other', 'description' => 'B', 'quantity_millis' => 1000, 'unit_price_cents' => 999, 'included' => true, 'taxable' => false, 'sort_order' => 20]);
        app(InvoiceCalculator::class)->recalculate($invoice);

        $invoice->refresh()->load('lines');
        $this->assertSame(2000, $invoice->subtotal_cents);
        $this->assertSame(100, $invoice->discount_total_cents);
        $this->assertSame(78, $invoice->tax_total_cents);
        $this->assertSame(1978, $invoice->total_cents);
        $this->assertSame(100, $invoice->lines->sum('discount_cents'));
        $this->assertSame([51, 49], $invoice->lines->pluck('discount_cents')->all());
        $this->assertSame([78, 0], $invoice->lines->pluck('tax_cents')->all());
    }

    public function test_issue_is_immutable_and_generates_an_authorized_private_pdf(): void
    {
        [$organization, $admin, $handoff] = $this->billingScenario(false);
        Queue::fake();
        Storage::fake('local');
        $logoKey = 'organization-branding/test-logo.png';
        Storage::disk('local')->put($logoKey, file_get_contents(public_path('images/newday-logo.png')));
        $logo = OrganizationBrandAsset::query()->create([
            'organization_id' => $organization->id, 'variant' => 'full', 'storage_disk' => 'local',
            'storage_key' => $logoKey, 'mime_type' => 'image/png', 'byte_size' => Storage::disk('local')->size($logoKey),
            'width' => 600, 'height' => 200, 'uploaded_by_id' => $admin->id,
        ]);
        $organization->update(['full_logo_asset_id' => $logo->id]);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        PaymentProviderConfiguration::query()->create(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(), 'provider' => 'stripe', 'environment' => 'test', 'api_secret' => 'sk_test_phase6', 'webhook_secret' => 'whsec_phase6', 'credential_fingerprint' => 'PHASE6000000', 'enabled' => true, 'connection_status' => 'connected']);
        $invoice->forceFill(['preferred_payment_provider' => 'stripe'])->save();
        $this->assertSame('NewDay Tech', $invoice->seller_name);
        $this->assertSame('billing@newdaytech.net', $invoice->seller_email);
        $this->assertSame($logo->id, $invoice->seller_logo_asset_id);
        $workflow->markReady($invoice, $admin);
        $issued = $workflow->issue($invoice, $admin, (string) Str::uuid());
        $this->assertSame('issued', $issued->status);
        Queue::assertPushed(RenderInvoicePdf::class);
        (new RenderInvoicePdf($issued->id))->handle(app(IncidentRecorder::class));
        $issued->refresh();
        $this->assertSame('ready', $issued->pdf_status);
        Storage::disk('local')->assertExists($issued->pdf_key);
        $organization->update(['name' => 'Changed After Issue', 'full_logo_asset_id' => null]);
        (new DeleteUnusedOrganizationBrandAsset($logo->id))->handle();
        $this->assertSame('NewDay Tech', $issued->fresh()->seller_name);
        $this->assertDatabaseHas('organization_brand_assets', ['id' => $logo->id]);
        Storage::disk('local')->assertExists($logoKey);

        $this->actingAs($admin)->put("/office/invoices/{$issued->id}", [])->assertSessionHasErrors();
        $this->actingAs($admin)->get("/invoices/{$issued->id}/pdf")->assertOk();
        $this->actingAs($admin)->get("/invoices/{$issued->id}/present")->assertOk()->assertDontSee('Internal billing note');
        $this->actingAs($admin)->post("/invoices/{$issued->id}/acknowledge", ['contact_name' => 'Customer Contact', 'confirmed' => '1', 'acknowledgment_token' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('invoice_acknowledgments', ['invoice_id' => $issued->id, 'contact_name' => 'Customer Contact', 'confirmed' => true]);
    }

    public function test_positive_invoice_can_be_reviewed_and_issued_without_hosted_payment_provider(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        Queue::fake();
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->assertGreaterThan(0, $invoice->total_cents);
        $this->assertNull($invoice->preferred_payment_provider);
        $workflow->markReady($invoice, $admin);
        $issued = $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());

        $this->assertSame('issued', $issued->status);
        $this->assertNull($issued->preferred_payment_provider);
        Queue::assertPushed(RenderInvoicePdf::class);
    }

    public function test_super_admin_can_void_and_reissue_without_changing_the_completed_ticket(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $replacement = $workflow->voidAndReissue($invoice, $admin, 'Customer billing identity correction', (string) Str::uuid());

        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame($invoice->id, $replacement->reissue_of_invoice_id);
        $this->assertSame(2, $replacement->generation);
        $this->assertNotSame($invoice->invoice_number, $replacement->invoice_number);
        $this->assertSame('completed', $invoice->serviceTicket->fresh()->status);
        $this->assertSame($replacement->id, $handoff->fresh()->current_invoice_id);
        $this->assertSame($invoice->lines()->count(), $replacement->lines()->count());
    }

    public function test_super_admin_deletes_unissued_invoice_restores_handoff_and_can_recreate(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}", [
            'deletion_reason' => 'Accidental duplicate draft',
            'confirm_invoice_number' => $invoice->invoice_number,
            'confirm_delete' => 1,
        ])->assertRedirect('/office/billing-handoffs');

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_lines', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_closeouts', ['invoice_id' => $invoice->id]);
        $this->assertDatabaseHas('billing_handoffs', [
            'id' => $handoff->id,
            'status' => 'ready',
            'current_invoice_id' => null,
            'handed_off_by_id' => null,
            'handed_off_at' => null,
            'acknowledgment_token' => null,
        ]);
        $event = AuditEvent::query()->where('event_type', 'invoice.unissued_deleted')->firstOrFail();
        $this->assertSame($handoff->id, $event->subject_id);
        $this->assertSame($invoice->invoice_number, $event->metadata['invoice_number']);
        $this->assertSame('Accidental duplicate draft', $event->metadata['deletion_reason']);

        $replacement = $workflow->createFromHandoff($handoff->fresh(), $admin, (string) Str::uuid());
        $this->assertNotSame($invoice->id, $replacement->id);
        $this->assertSame($replacement->id, $handoff->fresh()->current_invoice_id);
    }

    public function test_ready_invoice_can_be_deleted_but_issued_void_and_reissue_drafts_cannot(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $ready = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $ready->update(['status' => 'ready_for_review']);
        $this->actingAs($admin)->delete("/office/invoices/{$ready->id}", [
            'deletion_reason' => 'Wrong draft', 'confirm_invoice_number' => $ready->invoice_number, 'confirm_delete' => 1,
        ])->assertSessionHasNoErrors();

        $issued = $workflow->createFromHandoff($handoff->fresh(), $admin, (string) Str::uuid());
        $issued->update(['status' => 'issued', 'issued_at' => now(), 'issue_token' => (string) Str::uuid()]);
        $this->actingAs($admin)->delete("/office/invoices/{$issued->id}", [
            'deletion_reason' => 'Not allowed', 'confirm_invoice_number' => $issued->invoice_number, 'confirm_delete' => 1,
        ])->assertSessionHasErrors('invoice');
        $replacement = $workflow->voidAndReissue($issued, $admin, 'Correction', (string) Str::uuid());
        $this->actingAs($admin)->delete("/office/invoices/{$replacement->id}", [
            'deletion_reason' => 'Not allowed', 'confirm_invoice_number' => $replacement->invoice_number, 'confirm_delete' => 1,
        ])->assertSessionHasErrors('invoice');
        $this->actingAs($admin)->delete("/office/invoices/{$issued->id}", [
            'deletion_reason' => 'Not allowed', 'confirm_invoice_number' => $issued->invoice_number, 'confirm_delete' => 1,
        ])->assertSessionHasErrors('invoice');
    }

    public function test_payment_attempt_authorization_and_organization_scope_guard_draft_deletion(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $configuration = PaymentProviderConfiguration::query()->create([
            'organization_id' => $invoice->organization_id, 'public_id' => (string) Str::uuid(), 'provider' => 'stripe',
            'environment' => 'test', 'enabled' => true, 'connection_status' => 'connected',
        ]);
        PaymentAttempt::query()->create([
            'organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id,
            'payment_provider_configuration_id' => $configuration->id, 'provider' => 'stripe', 'amount_cents' => 100,
            'status' => 'open', 'idempotency_key' => (string) Str::uuid(), 'return_token_hash' => hash('sha256', Str::random(64)),
        ]);
        $payload = ['deletion_reason' => 'Not allowed', 'confirm_invoice_number' => $invoice->invoice_number, 'confirm_delete' => 1];
        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}", $payload)->assertSessionHasErrors('invoice');

        [$billing] = $this->userWithRole('billing', $invoice->organization);
        $this->actingAs($billing)->delete("/office/invoices/{$invoice->id}", $payload)->assertForbidden();
        [$outsider] = $this->userWithRole('super_admin');
        $this->actingAs($outsider)->delete("/office/invoices/{$invoice->id}", $payload)->assertNotFound();
    }

    public function test_seeded_invoice_capability_matrix_and_cross_organization_scope(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        [$reviewer] = $this->userWithRole('reviewer', $invoice->organization);
        [$billing] = $this->userWithRole('billing', $invoice->organization);
        [$technician, , $technicianMembership] = $this->userWithRole('technician', $invoice->organization);
        [$outsider] = $this->userWithRole('super_admin');

        $this->actingAs($reviewer)->get("/office/invoices/{$invoice->id}")->assertOk()->assertSee('Read-only billing context')->assertDontSee('Internal billing note');
        $this->actingAs($reviewer)->get("/invoices/{$invoice->id}/pdf")->assertForbidden();
        $this->actingAs($billing)->get("/office/invoices/{$invoice->id}")->assertOk()->assertSee('Invoice lines');
        $this->actingAs($technician)->get("/office/invoices/{$invoice->id}")->assertForbidden();
        $technicianMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'invoices.present')->firstOrFail(), ['effect' => 'grant']);
        $this->actingAs($technician)->get("/invoices/{$invoice->id}/present")->assertOk()->assertDontSee('Internal billing note');
        $this->actingAs($outsider)->get("/office/invoices/{$invoice->id}")->assertNotFound();
        $this->actingAs($outsider)->get("/invoices/{$invoice->id}/present")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['organization_id' => $outsider->memberships()->firstOrFail()->organization_id, 'event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_billing_queue_and_invoice_use_approved_workspace_conventions(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->actingAs($admin)->get('/office/billing-handoffs')
            ->assertRedirect(route('office.invoices.index', ['workspace' => 'ready_to_invoice']));

        $this->actingAs($admin)->get('/office/invoices')
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('aria-label="Billing and invoice status"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee($invoice->invoice_number)
            ->assertSee('Payment');

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('data-invoice-command-bar', false)
            ->assertSee('aria-label="Invoice actions"', false)
            ->assertSee('data-invoice-workspace', false)
            ->assertSee('data-invoice-billing-dialog', false)
            ->assertSee('data-invoice-billing-open', false)
            ->assertSee('Ready for review')
            ->assertSee('Delete unissued invoice')
            ->assertDontSee('data-office-detail-grid', false);

        $invalidBilling = $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->followingRedirects()->put("/office/invoices/{$invoice->id}", [
            'form_context' => 'billing', 'billing_name' => '', 'payment_terms' => 'custom',
            'tax_rate_percent' => 'not-a-rate',
        ]);
        $invalidBilling
            ->assertSee('data-auto-open="true"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="not-a-rate"', false);
    }

    public function test_invoice_ledger_is_the_primary_capability_aware_billing_workspace(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->actingAs($admin)->get(route('office.billing-handoffs.index'))
            ->assertRedirect(route('office.invoices.index', ['workspace' => 'ready_to_invoice']));
        $this->actingAs($admin)->get(route('office.invoices.index'))
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('Billing / Invoices')
            ->assertSee('Ready to Invoice')
            ->assertDontSee('>Queue<', false)
            ->assertSee('aria-label="Invoice filters"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee('Ticket / Project')
            ->assertSee($invoice->invoice_number);

        [$reviewer] = $this->userWithRole('reviewer', $invoice->organization);
        $this->actingAs($reviewer)->get(route('office.invoices.index'))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('href="'.route('office.invoices.index').'"', false)
            ->assertDontSee('href="'.route('office.billing-handoffs.index').'"', false);
        $this->actingAs($reviewer)->get(route('office.billing-handoffs.index'))->assertForbidden();

        [$technician] = $this->userWithRole('technician', $invoice->organization);
        $this->actingAs($technician)->get(route('office.invoices.index'))->assertForbidden();
    }

    public function test_ready_handoff_appears_in_unified_invoice_workspace_and_creates_draft(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);

        $this->actingAs($admin)->get(route('office.invoices.index'))
            ->assertOk()
            ->assertSee('data-ready-handoff-row', false)
            ->assertSee('data-ready-handoff-card', false)
            ->assertSee('Ready to Invoice')
            ->assertSee($handoff->serviceTicket->ticket_number)
            ->assertSee('Create invoice')
            ->assertSee('action="'.route('office.billing-handoffs.invoice.store', $handoff).'"', false);

        $this->actingAs($admin)->post(route('office.billing-handoffs.invoice.store', $handoff), [
            'creation_token' => (string) Str::uuid(),
        ])->assertRedirect();

        $this->assertNotNull($handoff->fresh()->current_invoice_id);
        $this->actingAs($admin)->get(route('office.invoices.index', ['workspace' => 'ready_to_invoice']))
            ->assertOk()
            ->assertDontSee('data-ready-handoff-row', false)
            ->assertDontSee($handoff->serviceTicket->ticket_number);
    }

    public function test_invoice_ledger_search_filters_sorting_pagination_and_organization_scope(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $invoice->forceFill(['status' => 'issued', 'issued_at' => now()->subDays(4), 'due_on' => today()->subDay(), 'total_cents' => 21000])->save();
        $ticket = $invoice->serviceTicket;
        $customer = $invoice->customer;

        foreach (range(2, 27) as $generation) {
            $copy = $invoice->replicate();
            $copy->forceFill([
                'generation' => $generation,
                'invoice_number' => sprintf('NDT-INV-2026-%04d', 6000 + $generation),
                'status' => $generation === 2 ? 'void' : 'draft',
                'creation_token' => (string) Str::uuid(),
                'issue_token' => null,
                'issued_at' => null,
                'issued_by_id' => null,
                'due_on' => null,
                'total_cents' => $generation * 100,
            ])->save();
        }

        $base = route('office.invoices.index');
        foreach ([
            ['invoice' => $invoice->invoice_number],
            ['customer' => $customer->display_name],
            ['customer' => $customer->legal_name],
            ['ticket' => $ticket->ticket_number],
            ['ticket' => $ticket->title],
            ['status' => 'issued'],
            ['payment_state' => 'unpaid'],
            ['balance_state' => 'open'],
            ['balance_state' => 'overdue'],
            ['date_from' => now()->subDays(5)->toDateString(), 'date_to' => now()->subDays(3)->toDateString()],
        ] as $query) {
            $this->actingAs($admin)->get($base.'?'.http_build_query(['invoice' => $invoice->invoice_number] + $query))->assertOk()->assertSee($invoice->invoice_number);
        }

        $this->actingAs($admin)->get($base.'?invoice=does-not-exist')->assertOk()->assertDontSee($invoice->invoice_number);
        $this->actingAs($admin)->get($base.'?sort=invoice&direction=asc')->assertOk()
            ->assertSeeInOrder([$invoice->invoice_number, 'NDT-INV-2026-6002']);
        $this->actingAs($admin)->get($base.'?sort=invoice&direction=asc')->assertOk()->assertSee('page=2', false);

        $invoice->paymentTransactions()->create([
            'organization_id' => $invoice->organization_id, 'type' => 'payment', 'status' => 'succeeded',
            'provider' => 'manual', 'method' => 'cash', 'amount_cents' => $invoice->total_cents,
            'idempotency_key' => (string) Str::uuid(), 'received_at' => now(), 'confirmed_at' => now(), 'recorded_by_id' => $admin->id,
        ]);
        $this->actingAs($admin)->get($base.'?payment_state=paid&balance_state=paid')->assertOk()->assertSee($invoice->invoice_number);
        $this->actingAs($admin)->get($base.'?balance_state=overdue')->assertOk()->assertDontSee($invoice->invoice_number);

        [$outsider] = $this->userWithRole('super_admin');
        $this->actingAs($outsider)->get($base.'?invoice='.urlencode($invoice->invoice_number))->assertOk()->assertSee('No billing activity found');
    }

    public function test_invoice_command_bar_tracks_lifecycle_and_payment_state_without_weakening_actions(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Ready for review')->assertSee('Billing details')->assertSee('Delete unissued invoice');

        $invoice->update(['status' => 'ready_for_review']);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Issue invoice')->assertSee('Confirm totals')->assertSee('href="#invoice-lines"', false);

        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Balance due')->assertSee('Print / PDF options')->assertSee('Present / collect payment')->assertSee('Record payment')
            ->assertDontSee('data-invoice-billing-dialog', false)->assertDontSee('Delete unissued invoice');

        $invoice->paymentTransactions()->create([
            'organization_id' => $invoice->organization_id, 'type' => 'payment', 'status' => 'succeeded',
            'provider' => 'manual', 'method' => 'cash', 'amount_cents' => $invoice->total_cents,
            'idempotency_key' => (string) Str::uuid(), 'received_at' => now(), 'confirmed_at' => now(), 'recorded_by_id' => $admin->id,
        ]);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Paid')->assertSee('Payments / receipts')->assertDontSee('Pay securely');
    }

    public function test_invoice_items_are_compact_and_open_only_the_selected_editor(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $line = $invoice->lines->firstOrFail();

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertSee('data-invoice-item-workspace', false)
            ->assertSee('data-invoice-item-table', false)
            ->assertSee('data-invoice-item-cards', false)
            ->assertSee('Item / description')
            ->assertSee('+ Add Catalog Item')
            ->assertSee('+ Add Manual Line')
            ->assertSee("data-invoice-item-open=\"invoice-line-editor-{$line->id}\"", false)
            ->assertSee("id=\"invoice-line-editor-{$line->id}\"", false)
            ->assertSee('Approved Work &amp; Billing Sources', false)
            ->assertSee('Internal Billing Information')
            ->assertSee('Audit History');

        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertDontSee('data-invoice-item-dialog', false)
            ->assertDontSee('+ Add Manual Line');
    }

    public function test_invoice_item_validation_reopens_the_correct_editor_and_preserves_input(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $line = $invoice->lines->firstOrFail();

        $response = $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->followingRedirects()->put("/office/invoices/{$invoice->id}/lines/{$line->id}", [
            'line_form_context' => (string) $line->id,
            'line_type' => 'labor',
            'description' => 'Preserved edited description',
            'quantity' => 'not-a-number',
            'unit' => 'hour',
            'unit_price' => '120.00',
            'included' => '1',
            'labor_rate_id' => $line->labor_rate_id,
            'override_reason' => 'Correction',
        ]);

        $response->assertOk()
            ->assertSee('data-auto-open="true"', false)
            ->assertSee('value="Preserved edited description"', false)
            ->assertSee('value="not-a-number"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('This invoice item needs attention');

        $manual = $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->followingRedirects()->post("/office/invoices/{$invoice->id}/lines", [
            'line_form_context' => 'manual',
            'line_type' => 'other',
            'description' => 'Preserved manual line',
            'quantity' => '1',
            'unit_price' => '',
            'included' => '1',
            'override_reason' => 'One-off work',
        ]);

        $manual->assertOk()
            ->assertSee('id="invoice-manual-line-dialog"', false)
            ->assertSee('data-auto-open="true"', false)
            ->assertSee('value="Preserved manual line"', false)
            ->assertSee('This manual line needs attention');
    }

    public function test_manual_and_catalog_lines_can_be_removed_from_a_draft_with_durable_audit_snapshots(): void
    {
        [$organization, $admin, $handoff] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $manual = $workflow->addLine($invoice, $admin, [
            'line_type' => 'other',
            'description' => 'Administrative service charge',
            'quantity_millis' => 1000,
            'unit' => 'each',
            'unit_price_cents' => 2500,
            'included' => true,
            'billing_treatment' => 'billable',
            'taxable' => false,
        ]);
        $catalogService = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-RES-IT')->firstOrFail();
        $catalog = $workflow->addCatalogLine(
            $invoice,
            $admin,
            app(CatalogLineSnapshotFactory::class)->create($organization->id, 'service', $catalogService->id, 1000),
        );
        $startingTotal = $invoice->fresh()->total_cents;

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertSee("data-invoice-item-open=\"invoice-line-remove-{$manual->id}\"", false)
            ->assertSee("id=\"invoice-line-remove-{$manual->id}\"", false)
            ->assertSee('Remove invoice line?');

        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$manual->id}", [
            'line_remove_context' => (string) $manual->id,
        ])->assertRedirect("/office/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('invoice_lines', ['id' => $manual->id]);
        $this->assertSame($startingTotal - 2500, $invoice->fresh()->total_cents);
        $manualEvent = AuditEvent::query()->where('event_type', 'invoice.line_removed')->where('subject_id', $invoice->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $manualEvent->actor_id);
        $this->assertSame($manual->id, $manualEvent->metadata['invoice_line_id']);
        $this->assertSame('Administrative service charge', $manualEvent->metadata['description']);
        $this->assertSame(2500, $manualEvent->metadata['amount_cents']);
        $this->assertSame('Removed while editing the invoice.', $manualEvent->metadata['reason']);

        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$catalog->id}", [
            'line_remove_context' => (string) $catalog->id,
        ])->assertRedirect("/office/invoices/{$invoice->id}")->assertSessionHasNoErrors();
        $catalogEvent = AuditEvent::query()->where('event_type', 'invoice.line_removed')->where('subject_id', $invoice->id)->latest('id')->firstOrFail();
        $this->assertSame('service', $catalogEvent->metadata['source_provenance']['catalog_item_type']);
        $this->assertSame($catalogService->id, $catalogEvent->metadata['source_provenance']['catalog_source_id']);
        $this->assertSame('LABOR-RES-IT', $catalogEvent->metadata['source_provenance']['catalog_code_snapshot']);
        $this->assertDatabaseHas('catalog_services', ['id' => $catalogService->id]);
    }

    public function test_source_generated_line_removal_requires_a_reason_and_preserves_all_source_evidence(): void
    {
        [$organization, $admin, $handoff] = $this->billingScenario();
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $labor = $invoice->lines->firstWhere('line_type', 'labor');
        $part = $invoice->lines->firstWhere('source_part_proposal_id', '!=', null);
        $visit = $labor->source_visit_id;
        $closeout = $labor->source_closeout_id;
        $review = $labor->source_review_id;
        $timeEntry = VisitTimeEntry::query()->where('visit_id', $visit)->firstOrFail();
        $proposal = $part->source_part_proposal_id;
        $startingTotal = $invoice->fresh()->total_cents;

        $validation = $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->followingRedirects()->delete("/office/invoices/{$invoice->id}/lines/{$labor->id}", [
            'line_remove_context' => (string) $labor->id,
        ]);
        $validation->assertOk()
            ->assertSee('data-auto-open="true"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Explain why this approved-work charge is being removed.');
        $this->assertDatabaseHas('invoice_lines', ['id' => $labor->id]);
        $this->assertSame($startingTotal, $invoice->fresh()->total_cents);

        $reason = 'Warranty callback; labor will not be charged.';
        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$labor->id}", [
            'line_remove_context' => (string) $labor->id,
            'reason' => $reason,
        ])->assertRedirect("/office/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visits', ['id' => $visit]);
        $this->assertDatabaseHas('closeouts', ['id' => $closeout]);
        $this->assertDatabaseHas('closeout_reviews', ['id' => $review]);
        $this->assertDatabaseHas('visit_time_entries', ['id' => $timeEntry->id]);
        $event = AuditEvent::query()->where('event_type', 'invoice.line_removed')->where('subject_id', $invoice->id)->latest('id')->firstOrFail();
        $this->assertSame($reason, $event->metadata['reason']);
        $this->assertSame($visit, $event->metadata['source_provenance']['source_visit_id']);
        $this->assertSame($closeout, $event->metadata['source_provenance']['source_closeout_id']);
        $this->assertSame($review, $event->metadata['source_provenance']['source_review_id']);

        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$part->id}", [
            'line_remove_context' => (string) $part->id,
            'reason' => 'Customer supplied this component.',
        ])->assertRedirect("/office/invoices/{$invoice->id}");
        $this->assertDatabaseHas('visit_part_proposals', ['id' => $proposal]);
        $partEvent = AuditEvent::query()->where('event_type', 'invoice.line_removed')->where('subject_id', $invoice->id)->latest('id')->firstOrFail();
        $this->assertSame($proposal, $partEvent->metadata['source_provenance']['source_part_proposal_id']);
    }

    public function test_ready_invoice_lines_can_be_removed_but_issued_and_void_invoices_remain_immutable(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $readyLine = $invoice->lines->firstOrFail();
        $invoice->update(['status' => 'ready_for_review']);

        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$readyLine->id}", [
            'line_remove_context' => (string) $readyLine->id,
            'reason' => 'Reviewed as warranty labor.',
        ])->assertRedirect("/office/invoices/{$invoice->id}")->assertSessionHasNoErrors();
        $this->assertSame('ready_for_review', $invoice->fresh()->status);
        $this->assertDatabaseMissing('invoice_lines', ['id' => $readyLine->id]);

        $immutableLine = $invoice->lines()->firstOrFail();
        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->delete("/office/invoices/{$invoice->id}/lines/{$immutableLine->id}", [
            'line_remove_context' => (string) $immutableLine->id,
            'reason' => 'Forged issued edit.',
        ])->assertSessionHasErrors('invoice');
        $this->assertDatabaseHas('invoice_lines', ['id' => $immutableLine->id]);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertDontSee('invoice-line-remove-', false);

        $invoice->update(['status' => 'void', 'voided_at' => now(), 'voided_by_id' => $admin->id, 'void_reason' => 'Test history']);
        $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")->delete("/office/invoices/{$invoice->id}/lines/{$immutableLine->id}", [
            'line_remove_context' => (string) $immutableLine->id,
            'reason' => 'Forged void edit.',
        ])->assertSessionHasErrors('invoice');
        $this->assertDatabaseHas('invoice_lines', ['id' => $immutableLine->id]);
    }

    public function test_line_removal_is_organization_scoped_and_honors_invoice_management_authorization(): void
    {
        [$organization, $admin, $handoff] = $this->billingScenario(false);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $manual = app(InvoiceWorkflow::class)->addLine($invoice, $admin, [
            'line_type' => 'other', 'description' => 'Authorized removal', 'quantity_millis' => 1000,
            'unit_price_cents' => 1000, 'included' => true, 'taxable' => false,
        ]);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$billing, , $billingMembership] = $this->userWithRole('billing', $organization);
        [$inactive, , $inactiveMembership] = $this->userWithRole('super_admin', $organization);
        $inactiveMembership->update(['status' => 'inactive']);

        $this->actingAs($reviewer)->delete("/office/invoices/{$invoice->id}/lines/{$manual->id}", [
            'line_remove_context' => (string) $manual->id,
        ])->assertForbidden();
        $this->actingAs($inactive)->delete("/office/invoices/{$invoice->id}/lines/{$manual->id}", [
            'line_remove_context' => (string) $manual->id,
        ])->assertForbidden();

        $manage = Capability::query()->where('key', 'invoices.manage')->firstOrFail();
        $billingMembership->capabilityOverrides()->attach($manage, ['effect' => 'deny']);
        $this->actingAs($billing)->delete("/office/invoices/{$invoice->id}/lines/{$manual->id}", [
            'line_remove_context' => (string) $manual->id,
        ])->assertForbidden();

        [, $foreignAdmin, $foreignHandoff] = $this->billingScenario(false);
        $foreignInvoice = app(InvoiceWorkflow::class)->createFromHandoff($foreignHandoff, $foreignAdmin, (string) Str::uuid());
        $foreignLine = $foreignInvoice->lines->firstOrFail();
        $this->actingAs($admin)->delete("/office/invoices/{$foreignInvoice->id}/lines/{$foreignLine->id}", [
            'line_remove_context' => (string) $foreignLine->id,
        ])->assertNotFound();
        $this->actingAs($admin)->delete("/office/invoices/{$invoice->id}/lines/{$foreignLine->id}", [
            'line_remove_context' => (string) $foreignLine->id,
        ])->assertNotFound();
        $this->assertDatabaseHas('invoice_lines', ['id' => $manual->id]);
        $this->assertTrue(AuditEvent::query()
            ->where('event_type', 'security.cross_organization_record_denied')
            ->where('subject_id', $organization->id)
            ->get()
            ->contains(fn (AuditEvent $event): bool => ($event->metadata['record_type'] ?? null) === 'invoice_line'
                && (int) ($event->metadata['record_id'] ?? 0) === (int) $foreignLine->id));
    }

    public function test_line_removal_rolls_back_deletion_and_totals_when_a_later_write_fails(): void
    {
        [, $admin, $handoff] = $this->billingScenario(false);
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $manual = $workflow->addLine($invoice, $admin, [
            'line_type' => 'other', 'description' => 'Rollback line', 'quantity_millis' => 1000,
            'unit_price_cents' => 4000, 'included' => true, 'taxable' => false,
        ]);
        $startingTotal = $invoice->fresh()->total_cents;
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated durable audit failure'));

        try {
            app(InvoiceWorkflow::class)->removeLine($invoice, $manual, $admin);
            $this->fail('The simulated audit failure should abort removal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated durable audit failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('invoice_lines', ['id' => $manual->id, 'total_cents' => 4000]);
        $this->assertSame($startingTotal, $invoice->fresh()->total_cents);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'invoice.line_removed', 'subject_id' => $invoice->id]);
    }

    /** @return array{Organization, User, BillingHandoff, Visit, Visit} */
    private function billingScenario(bool $withPart = true): array
    {
        $organization = Organization::factory()->create([
            'name' => 'NewDay Tech', 'legal_name' => 'NewDay Tech LLC',
            'email' => 'billing@newdaytech.net', 'phone' => '555-0100',
            'address_line_1' => '100 Service Way', 'city' => 'Dallas',
            'state' => 'TX', 'postal_code' => '75001', 'timezone' => 'America/Chicago',
        ]);
        [$admin] = $this->userWithRole('super_admin', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Invoice Customer', 'legal_name' => 'Invoice Customer LLC', 'email' => 'billing@example.test']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Main Site', 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-6001', 'title' => 'Multi-visit repair', 'priority' => 'normal', 'source' => 'phone', 'status' => 'completed']);
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization, $admin);
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'seller_name' => 'NewDay Tech', 'seller_legal_name' => 'NewDay Tech LLC', 'seller_email' => 'billing@newdaytech.net', 'seller_phone' => '555-0100', 'seller_address_line_1' => '100 Service Way', 'seller_city' => 'Dallas', 'seller_state' => 'TX', 'seller_postal_code' => '75001', 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_tax_rate_basis_points' => 0, 'default_labor_catalog_service_id' => $labor->id, 'labor_billing_increment_minutes' => 15, 'labor_rounding_rule' => 'up', 'minimum_billable_minutes' => 0]);
        $first = $this->approvedVisit($organization, $ticket, $location, $admin, 'needs_return_trip', 61);
        $second = $this->approvedVisit($organization, $ticket, $location, $admin, 'resolved', 16, 'other');
        if ($withPart) {
            VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $first->id, 'closeout_id' => $first->current_closeout_id, 'proposed_by_id' => $admin->id, 'description' => 'Replacement module', 'quantity' => 2, 'unit' => 'each', 'billing_treatment' => 'billable']);
        }
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $second->id, 'closeout_id' => $second->current_closeout_id, 'status' => 'ready', 'created_by_id' => $admin->id]);

        return [$organization, $admin, $handoff, $first, $second];
    }

    private function approvedVisit(Organization $organization, ServiceTicket $ticket, ServiceLocation $location, User $actor, string $outcome, int $minutes, string $category = 'on_site'): Visit
    {
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => 'approved', 'timezone' => $location->timezone]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 2, 'outcome' => $outcome, 'diagnosis' => 'Diagnosis', 'work_performed' => 'Work', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $actor->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $actor->id, 'category' => $category, 'started_at' => Carbon::parse('2026-08-08 14:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-08-08 14:00:00', 'UTC')->addMinutes($minutes), 'source' => 'manual']);
        CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $actor->id, 'decision' => 'approved', 'self_review_override' => true, 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);

        return $visit->fresh();
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
