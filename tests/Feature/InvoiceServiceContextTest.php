<?php

namespace Tests\Feature;

use App\Domain\InvoiceCalculator;
use App\Domain\InvoiceServiceContextProjection;
use App\Domain\InvoiceServiceSnapshotFactory;
use App\Domain\InvoiceWorkflow;
use App\Domain\NewDayCatalogBootstrap;
use App\Jobs\RenderInvoicePdf;
use App\Models\BillingHandoff;
use App\Models\CatalogService;
use App\Models\Closeout;
use App\Models\CloseoutAcknowledgmentSignature;
use App\Models\CloseoutReview;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAcknowledgment;
use App\Models\InvoiceServiceSnapshot;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceServiceContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Queue::fake();
    }

    public function test_projection_is_an_explicit_customer_safe_scalar_allowlist(): void
    {
        [$organization, $admin, , $invoice, $visit, $ticket] = $this->scenario();
        $projection = app(InvoiceServiceContextProjection::class)->build($invoice);

        $this->assertSame(1, $projection['schema_version']);
        $this->assertSame($ticket->ticket_number, $projection['ticket']['number']);
        $this->assertSame('Replace failed access point', $projection['requested_service']['scope']);
        $this->assertSame('Restore wireless service.', $projection['requested_service']['summary']);
        $this->assertSame('Invoice Customer', $projection['customer']['name']);
        $this->assertSame('Site Contact', $projection['contact']['name']);
        $this->assertSame('Camera C-14 offline', $projection['work_items'][0]['title']);
        $this->assertSame('Follow-up required', $projection['work_items'][0]['status']);
        $this->assertSame($visit->displayNumber(), $projection['work_items'][0]['discovered_visit']);
        $this->assertSame('NDT-ST-2026-6999', $projection['work_items'][0]['follow_up_ticket']);
        $this->assertSame([$admin->name], $projection['visits'][0]['technicians']);
        $this->assertSame('2026-08-08T15:15:00+00:00', $projection['visits'][0]['site_window']['start_at']);
        $this->assertSame('2026-08-08T16:45:00+00:00', $projection['visits'][0]['site_window']['end_at']);
        $this->assertSame('Replaced and tested the access point.', $projection['visits'][0]['work_performed']);
        $this->assertSame('Monitor wireless coverage.', $projection['visits'][0]['recommendations']);
        $this->assertSame('Replacement module', $projection['visits'][0]['parts'][0]['description']);
        $this->assertSame('signed', $projection['visits'][0]['acknowledgment']['type']);
        $this->assertSame('Jane Customer', $projection['visits'][0]['acknowledgment']['name']);

        $json = InvoiceServiceSnapshotFactory::canonicalJson($projection);
        foreach (['image_url', 'storage_key', 'storage_disk', 'billing_treatment', 'correction_reason', 'approved_minutes', 'allocation', 'internal_note', 'audit'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertStringNotContainsString('travel', $json);
        $this->assertStringNotContainsString('other', $json);
        $this->assertStringNotContainsString('Internal work detail', $json);
    }

    public function test_issue_atomically_creates_one_hashed_snapshot_and_issued_views_ignore_live_changes(): void
    {
        [, $admin, , $invoice, , $ticket] = $this->scenario();
        $workflow = app(InvoiceWorkflow::class);
        $this->assertDatabaseMissing('invoice_service_snapshots', ['invoice_id' => $invoice->id]);
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Live preview — captured when Invoice is issued')->assertSee('Replace failed access point');

        $workflow->markReady($invoice, $admin);
        $token = (string) Str::uuid();
        $issued = $workflow->issue($invoice->fresh(), $admin, $token);
        $snapshot = InvoiceServiceSnapshot::query()->where('invoice_id', $issued->id)->firstOrFail();
        $originalPayload = $snapshot->snapshot_json;
        $originalHash = $snapshot->snapshot_sha256;
        $this->assertSame(hash('sha256', InvoiceServiceSnapshotFactory::canonicalJson($originalPayload)), $originalHash);
        $this->assertSame(1, $snapshot->schema_version);
        $this->assertSame($ticket->id, $snapshot->service_ticket_id);
        $this->assertSame($issued->organization_id, $snapshot->organization_id);

        $ticket->update(['description' => 'Changed after issue', 'title' => 'Changed live title']);
        $retry = $workflow->issue($issued->fresh(), $admin, $token);
        $this->assertSame($issued->id, $retry->id);
        $this->assertDatabaseCount('invoice_service_snapshots', 1);
        $this->assertSame($originalPayload, $snapshot->fresh()->snapshot_json);
        $this->assertSame($originalHash, $snapshot->fresh()->snapshot_sha256);

        $this->actingAs($admin)->get("/office/invoices/{$issued->id}")
            ->assertOk()->assertSee('Service Details — locked at issuance')->assertSee('Replace failed access point')->assertDontSee('Changed after issue');
        $this->actingAs($admin)->get("/invoices/{$issued->id}/present")
            ->assertOk()->assertSee('Service Details')->assertSee('Replace failed access point')->assertDontSee('Changed after issue');
        InvoiceAcknowledgment::query()->create([
            'organization_id' => $issued->organization_id,
            'invoice_id' => $issued->id,
            'contact_name' => 'Invoice-only Presenter',
            'confirmed' => true,
            'presented_by_id' => $admin->id,
            'acknowledged_at' => now(),
            'acknowledgment_token' => (string) Str::uuid(),
        ]);
        $issued->load(['organization', 'serviceTicket', 'serviceLocation', 'serviceSnapshot', 'lines']);
        $pdfHtml = view('invoices.pdf', ['invoice' => $issued, 'logoDataUri' => 'data:image/png;base64,AA=='])->render();
        $this->assertStringContainsString('Replace failed access point', $pdfHtml);
        $this->assertStringContainsString('Camera C-14 offline', $pdfHtml);
        $this->assertStringContainsString('break-before:page;page-break-before:always', $pdfHtml);
        $this->assertStringContainsString('Customer acknowledgment', $pdfHtml);
        $this->assertStringNotContainsString('Invoice-only Presenter', $pdfHtml);
        $this->assertStringNotContainsString('Changed after issue', $pdfHtml);
        $this->assertStringNotContainsString('private/signature.png', $pdfHtml);
        Queue::assertPushed(RenderInvoicePdf::class);
    }

    public function test_snapshot_failure_rolls_back_issue_and_direct_invoices_remain_without_service_context(): void
    {
        [$organization, $admin, , $invoice] = $this->scenario();
        $workflow = app(InvoiceWorkflow::class);
        $workflow->markReady($invoice, $admin);
        $differentCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
        $invoice->update(['customer_id' => $differentCustomer->id]);

        try {
            $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());
            $this->fail('Expected mismatched invoice context to prevent issuance.');
        } catch (ValidationException) {
            $this->assertSame('ready_for_review', $invoice->fresh()->status);
            $this->assertDatabaseMissing('invoice_service_snapshots', ['invoice_id' => $invoice->id]);
        }

        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $direct = $workflow->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
        $direct->lines()->create(['organization_id' => $organization->id, 'line_type' => 'service_charge', 'description' => 'Direct consulting', 'quantity_millis' => 1000, 'unit' => 'each', 'unit_price_cents' => 10000, 'included' => true, 'taxable' => false, 'sort_order' => 10]);
        app(InvoiceCalculator::class)->recalculate($direct);
        $workflow->markReady($direct->fresh(), $admin);
        $workflow->issue($direct->fresh(), $admin, (string) Str::uuid());
        $this->assertDatabaseMissing('invoice_service_snapshots', ['invoice_id' => $direct->id]);
        $this->actingAs($admin)->get("/office/invoices/{$direct->id}")->assertOk()->assertDontSee('Service Details');
    }

    public function test_void_preserves_original_snapshot_and_reissue_captures_current_context(): void
    {
        [, $admin, , $invoice, , $ticket] = $this->scenario();
        $workflow = app(InvoiceWorkflow::class);
        $workflow->markReady($invoice, $admin);
        $issued = $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());
        $original = $issued->serviceSnapshot()->firstOrFail();
        $ticket->update(['description' => 'Updated scope for the corrected document']);

        $replacement = $workflow->voidAndReissue($issued->fresh(), $admin, 'Customer requested a corrected document', (string) Str::uuid());
        $this->assertNull($replacement->serviceSnapshot()->first());
        $this->assertDatabaseHas('invoice_service_snapshots', ['id' => $original->id, 'invoice_id' => $issued->id]);
        $this->actingAs($admin)->get("/office/invoices/{$replacement->id}")->assertOk()->assertSee('Updated scope for the corrected document');

        $workflow->markReady($replacement, $admin);
        $workflow->issue($replacement->fresh(), $admin, (string) Str::uuid());
        $newSnapshot = $replacement->serviceSnapshot()->firstOrFail();
        $this->assertNotSame($original->id, $newSnapshot->id);
        $this->assertNotSame($original->snapshot_sha256, $newSnapshot->snapshot_sha256);
        $this->assertSame('Replace failed access point', $original->snapshot_json['requested_service']['scope']);
        $this->assertSame('Updated scope for the corrected document', $newSnapshot->snapshot_json['requested_service']['scope']);
    }

    public function test_legacy_issued_invoice_is_not_backfilled_or_fabricated_by_views(): void
    {
        [, $admin, , $invoice] = $this->scenario();
        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id, 'pdf_status' => 'pending']);

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()->assertSee('Detailed Service Ticket context was not snapshotted');
        $this->actingAs($admin)->get("/invoices/{$invoice->id}/present")
            ->assertOk()->assertSee($invoice->serviceTicket->ticket_number)->assertDontSee('Additional Work Items');
        $invoice->load(['organization', 'serviceTicket', 'serviceLocation', 'serviceSnapshot', 'lines']);
        $legacyPdf = view('invoices.pdf', ['invoice' => $invoice, 'logoDataUri' => 'data:image/png;base64,AA=='])->render();
        $this->assertStringContainsString($invoice->serviceTicket->ticket_number, $legacyPdf);
        $this->assertStringNotContainsString('Additional Work Items', $legacyPdf);
        $this->assertDatabaseMissing('invoice_service_snapshots', ['invoice_id' => $invoice->id]);
    }

    public function test_draft_and_issued_service_context_queries_remain_bounded(): void
    {
        [, $admin, , $invoice] = $this->scenario();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")->assertOk();
        $draftQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, $draftQueries, "Ticket Invoice draft used {$draftQueries} queries");

        $workflow = app(InvoiceWorkflow::class);
        $workflow->markReady($invoice, $admin);
        $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/invoices/{$invoice->id}/present")->assertOk();
        $issuedQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(30, $issuedQueries, "Issued Invoice presentation used {$issuedQueries} queries");
    }

    public function test_print_composer_is_static_private_and_uses_the_immutable_snapshot(): void
    {
        [, $admin, , $invoice, , $ticket] = $this->scenario();
        $invoice->update(['customer_note' => 'Please retain this customer-facing note.']);
        $workflow = app(InvoiceWorkflow::class);
        $workflow->markReady($invoice, $admin);
        $issued = $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());

        $this->get(route('invoices.print', $issued))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('invoices.present', $issued))
            ->assertOk()
            ->assertSee(route('invoices.print', $issued), false)
            ->assertSee('Print Invoice')
            ->assertSee('data-offline-write', false)
            ->assertSee('Invoice acknowledgment')
            ->assertSee('Payment');

        InvoiceAcknowledgment::query()->create([
            'organization_id' => $issued->organization_id,
            'invoice_id' => $issued->id,
            'contact_name' => 'Jane Customer',
            'confirmed' => true,
            'presented_by_id' => $admin->id,
            'acknowledged_at' => Carbon::parse('2026-08-24 18:05:00', 'UTC'),
            'acknowledgment_token' => (string) Str::uuid(),
        ]);
        $ticket->update(['description' => 'Changed live description after issue.']);

        $response = $this->actingAs($admin)->get(route('invoices.print', $issued));
        $response->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-robots-tag', 'noindex, nofollow')
            ->assertSee('data-print-composer', false)
            ->assertSee('data-print-section="financial-core"', false)
            ->assertSee('Please retain this customer-facing note.')
            ->assertSee('data-print-section="service-details"', false)
            ->assertSee('print-break-before', false)
            ->assertSee('Replace failed access point')
            ->assertSee('Camera C-14 offline')
            ->assertSee('Jane Customer')
            ->assertSee('data-print-section="invoice-acknowledgment"', false)
            ->assertSee('hidden', false)
            ->assertDontSee('Changed live description after issue.')
            ->assertDontSee('<form', false)
            ->assertDontSee('<details', false)
            ->assertDontSee('data-connectivity-banner', false)
            ->assertDontSee('Create secure checkout')
            ->assertDontSee('Refresh payment status')
            ->assertDontSee('internal_note')
            ->assertDontSee('private/signature.png');
    }

    public function test_print_composer_keeps_direct_and_legacy_ticket_invoices_bounded(): void
    {
        [$organization, $admin, , $invoice] = $this->scenario();
        $workflow = app(InvoiceWorkflow::class);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $direct = $workflow->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
        $direct->update(['customer_note' => 'Direct Invoice customer note.']);
        $direct->lines()->create([
            'organization_id' => $organization->id,
            'line_type' => 'service_charge',
            'description' => 'Direct consulting',
            'quantity_millis' => 1000,
            'unit' => 'each',
            'unit_price_cents' => 10000,
            'included' => true,
            'taxable' => false,
            'sort_order' => 10,
        ]);
        app(InvoiceCalculator::class)->recalculate($direct);
        $workflow->markReady($direct->fresh(), $admin);
        $workflow->issue($direct->fresh(), $admin, (string) Str::uuid());

        $this->actingAs($admin)->get(route('invoices.print', $direct))
            ->assertOk()
            ->assertSee('Direct invoice')
            ->assertSee('Direct Invoice customer note.')
            ->assertDontSee('include-service-details', false)
            ->assertDontSee('data-print-section="service-details"', false);

        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id, 'pdf_status' => 'pending']);
        $this->actingAs($admin)->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee($invoice->serviceTicket->ticket_number)
            ->assertDontSee('Additional Work Items')
            ->assertDontSee('include-service-details', false);
    }

    public function test_print_composer_enforces_tenant_scope_and_ignores_template_input(): void
    {
        [, $admin, , $invoice] = $this->scenario();
        [, , , $foreignInvoice] = $this->scenario();
        $invoice->update(['status' => 'issued', 'issued_at' => now(), 'pdf_status' => 'pending']);
        $foreignInvoice->update(['status' => 'issued', 'issued_at' => now(), 'pdf_status' => 'pending']);

        $this->actingAs($admin)->get(route('invoices.print', $foreignInvoice))->assertNotFound();
        $this->actingAs($admin)->get(route('invoices.print', $invoice).'?template=../../internal-note')
            ->assertOk()
            ->assertSee('data-print-composer', false)
            ->assertDontSee('internal-note');
    }

    public function test_print_composer_query_count_is_bounded_below_interactive_presentation(): void
    {
        [, $admin, , $invoice] = $this->scenario();
        $workflow = app(InvoiceWorkflow::class);
        $workflow->markReady($invoice, $admin);
        $workflow->issue($invoice->fresh(), $admin, (string) Str::uuid());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('invoices.present', $invoice))->assertOk();
        $presentationQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($admin)->get(route('invoices.print', $invoice))->assertOk();
        $printQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $printQueries, "Invoice Print Composer used {$printQueries} queries");
        $this->assertLessThan($presentationQueries, $printQueries, "Print used {$printQueries}; presentation used {$presentationQueries}");
    }

    /** @return array{Organization, User, BillingHandoff, Invoice, Visit, ServiceTicket} */
    private function scenario(): array
    {
        $organization = Organization::factory()->create([
            'name' => 'NewDay Tech', 'legal_name' => 'NewDay Tech LLC', 'email' => 'billing@newdaytech.net', 'phone' => '555-0100',
            'address_line_1' => '100 Service Way', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'timezone' => 'America/Chicago',
        ]);
        $admin = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Invoice Customer', 'legal_name' => 'Invoice Customer LLC']);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Site Contact', 'role' => 'Property Manager', 'active' => true, 'preferred' => true]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Main Site', 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-6001', 'title' => 'Wireless repair', 'description' => 'Replace failed access point', 'customer_visible_summary' => 'Restore wireless service.', 'priority' => 'normal', 'source' => 'phone', 'status' => 'completed']);
        $followUp = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-6999', 'title' => 'Camera follow-up', 'priority' => 'normal', 'source' => 'internal', 'status' => 'open']);
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization, $admin);
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'seller_name' => 'NewDay Tech', 'seller_legal_name' => 'NewDay Tech LLC', 'seller_email' => 'billing@newdaytech.net', 'seller_phone' => '555-0100', 'seller_address_line_1' => '100 Service Way', 'seller_city' => 'Dallas', 'seller_state' => 'TX', 'seller_postal_code' => '75001', 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_tax_rate_basis_points' => 0, 'default_labor_catalog_service_id' => $labor->id, 'labor_billing_increment_minutes' => 15, 'labor_rounding_rule' => 'up', 'minimum_billable_minutes' => 0]);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => 'approved', 'timezone' => $location->timezone]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true, 'assigned_by_id' => $admin->id]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 2, 'outcome' => 'resolved', 'diagnosis' => 'Failed radio', 'work_performed' => 'Replaced and tested the access point.', 'recommendations' => 'Monitor wireless coverage.', 'representative_name' => 'Jane Customer', 'representative_role' => 'Property Manager', 'acknowledged_at' => now(), 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        CloseoutAcknowledgmentSignature::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'signer_name' => 'Jane Customer', 'signer_role' => 'Property Manager', 'statement_version' => 1, 'statement_snapshot' => 'Acknowledgment statement', 'storage_disk' => 'local', 'storage_key' => 'private/signature.png', 'mime_type' => 'image/png', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'signed_at' => Carbon::parse('2026-08-08 17:00:00', 'UTC'), 'captured_by_id' => $admin->id]);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'travel', 'started_at' => Carbon::parse('2026-08-08 14:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-08-08 15:00:00', 'UTC'), 'source' => 'timer']);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'on_site', 'started_at' => Carbon::parse('2026-08-08 15:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-08-08 17:00:00', 'UTC'), 'corrected_started_at' => Carbon::parse('2026-08-08 15:15:00', 'UTC'), 'corrected_ended_at' => Carbon::parse('2026-08-08 16:45:00', 'UTC'), 'source' => 'timer', 'correction_reason' => 'Internal correction reason']);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'other', 'started_at' => Carbon::parse('2026-08-08 17:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-08-08 17:15:00', 'UTC'), 'source' => 'manual']);
        VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'proposed_by_id' => $admin->id, 'description' => 'Replacement module', 'quantity' => 1, 'unit' => 'each', 'billing_treatment' => 'billable', 'technician_note' => 'Internal technician note']);
        CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $admin->id, 'decision' => 'approved', 'self_review_override' => true, 'decision_reason' => 'Internal review reason', 'decision_token' => (string) Str::uuid(), 'decided_at' => now()]);
        $workItem = ServiceTicketWorkItem::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'discovered_visit_id' => $visit->id, 'origin' => 'field_discovered', 'title' => 'Camera C-14 offline', 'detail' => 'Internal work detail', 'work_note' => 'Internal work note', 'status' => 'needs_follow_up', 'follow_up_service_ticket_id' => $followUp->id, 'created_by_id' => $admin->id, 'updated_by_id' => $admin->id]);
        $workItem->visits()->attach($visit->id, ['organization_id' => $organization->id, 'first_touched_by_id' => $admin->id, 'first_touched_at' => now(), 'last_touched_by_id' => $admin->id, 'last_touched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready', 'created_by_id' => $admin->id]);
        $invoice = app(InvoiceWorkflow::class)->createFromHandoff($handoff, $admin, (string) Str::uuid());
        $invoice->lines()->whereNotNull('source_part_proposal_id')->update(['unit_price_cents' => 5000]);
        app(InvoiceCalculator::class)->recalculate($invoice);

        return [$organization, $admin, $handoff, $invoice, $visit, $ticket];
    }
}
