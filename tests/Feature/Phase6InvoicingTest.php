<?php

namespace Tests\Feature;

use App\Domain\InvoiceCalculator;
use App\Domain\InvoiceWorkflow;
use App\Jobs\DeleteUnusedOrganizationBrandAsset;
use App\Jobs\RenderInvoicePdf;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\BillingLaborRate;
use App\Models\Capability;
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
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $first->id, 'line_type' => 'labor', 'quantity_millis' => 1250, 'unit_price_cents' => 12000]);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $second->id, 'line_type' => 'labor', 'quantity_millis' => 500, 'unit_price_cents' => 12000]);
        $this->assertDatabaseHas('invoice_lines', ['invoice_id' => $invoice->id, 'source_part_proposal_id' => VisitPartProposal::query()->value('id'), 'unit_price_cents' => null]);
        $this->assertSame(21000, $invoice->fresh()->subtotal_cents);
        $this->assertSame($organization->id, $invoice->organization_id);
        foreach ($invoice->lines->where('line_type', 'labor') as $line) {
            $this->assertSame('Service Labor — Multi-visit repair', $line->description);
            $this->assertStringNotContainsString('Visit #', $line->description);
        }
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
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('aria-label="Billing queue filters"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee($invoice->invoice_number)
            ->assertSee('Payment');

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertSee('data-office-width="detail"', false)
            ->assertSee('aria-label="On this page"', false)
            ->assertSee('href="#approved-work"', false)
            ->assertSee('href="#invoice-lines"', false)
            ->assertSee('data-office-detail-grid', false);
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
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'seller_name' => 'NewDay Tech', 'seller_legal_name' => 'NewDay Tech LLC', 'seller_email' => 'billing@newdaytech.net', 'seller_phone' => '555-0100', 'seller_address_line_1' => '100 Service Way', 'seller_city' => 'Dallas', 'seller_state' => 'TX', 'seller_postal_code' => '75001', 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_tax_rate_basis_points' => 0]);
        BillingLaborRate::query()->create(['organization_id' => $organization->id, 'name' => 'Standard', 'hourly_rate_cents' => 12000, 'is_default' => true, 'active' => true, 'created_by_id' => $admin->id]);
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
