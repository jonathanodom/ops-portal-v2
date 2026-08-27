<?php

namespace Tests\Feature;

use App\Domain\FieldTestServiceTicketPurgePreview;
use App\Domain\FieldTestServiceTicketPurger;
use App\Exceptions\FieldTestPurgeStorageCleanupException;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\CloseoutAcknowledgmentSignature;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\FieldTestPurgeCleanup;
use App\Models\Invoice;
use App\Models\InvoiceAcknowledgment;
use App\Models\InvoiceCloseout;
use App\Models\InvoiceLine;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\Project;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\ServiceTicketNote;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use App\Payments\PaymentProviderResolver;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FieldTestServiceTicketPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_feature_is_default_off_and_direct_routes_are_not_found(): void
    {
        config()->set('field_test.destructive_service_ticket_purge_enabled', false);

        [$organization, $ticket] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->get(route('office.service-tickets.show', $ticket))
            ->assertOk()->assertDontSee('Permanently purge test Service Ticket');
        $this->actingAs($admin)->get(route('office.service-tickets.field-test-purge.confirm', $ticket))->assertNotFound();
        $this->actingAs($admin)->post(route('office.service-tickets.field-test-purge.destroy', $ticket), [
            'ticket_number' => $ticket->ticket_number,
            'acknowledge' => '1',
        ])->assertNotFound();
        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id]);
    }

    public function test_only_an_exact_super_admin_with_the_capability_can_open_the_tool(): void
    {
        config()->set('field_test.destructive_service_ticket_purge_enabled', true);
        [$organization, $ticket] = $this->ticketGraph();
        [$admin, , $adminMembership] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->get(route('office.service-tickets.field-test-purge.confirm', $ticket))
            ->assertOk()->assertSee($ticket->ticket_number)->assertSee('External payments are not reversed');

        foreach (['dispatcher', 'billing', 'reviewer'] as $role) {
            [$user, , $membership] = $this->userWithRole($role, $organization);
            if ($role === 'dispatcher') {
                $membership->capabilityOverrides()->attach(
                    Capability::query()->where('key', 'service_tickets.purge_test_data')->firstOrFail(),
                    ['effect' => 'grant']
                );
            }
            $this->actingAs($user)->get(route('office.service-tickets.field-test-purge.confirm', $ticket))->assertForbidden();
        }

        $adminMembership->capabilityOverrides()->attach(
            Capability::query()->where('key', 'service_tickets.purge_test_data')->firstOrFail(),
            ['effect' => 'deny']
        );
        $this->actingAs($admin)->get(route('office.service-tickets.field-test-purge.confirm', $ticket))->assertForbidden();
    }

    public function test_confirmation_and_organization_scope_are_enforced(): void
    {
        config()->set('field_test.destructive_service_ticket_purge_enabled', true);
        [$organization, $ticket] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->post(route('office.service-tickets.field-test-purge.destroy', $ticket), [
            'ticket_number' => 'WRONG', 'acknowledge' => '1',
        ])->assertSessionHasErrors('ticket_number');
        $this->actingAs($admin)->post(route('office.service-tickets.field-test-purge.destroy', $ticket), [
            'ticket_number' => $ticket->ticket_number,
        ])->assertSessionHasErrors('acknowledge');

        [, $foreignTicket] = $this->ticketGraph(Organization::factory()->create());
        $this->actingAs($admin)->get(route('office.service-tickets.field-test-purge.confirm', $foreignTicket))->assertNotFound();
        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('service_tickets', ['id' => $foreignTicket->id]);
    }

    public function test_dirty_ticket_graph_and_private_objects_are_purged_while_master_and_project_records_survive(): void
    {
        config()->set('field_test.destructive_service_ticket_purge_enabled', true);
        Storage::fake('purge-test');
        [$organization, $ticket, $customer, $location] = $this->ticketGraph();
        [$admin, , $membership] = $this->userWithRole('super_admin', $organization);
        [, $unrelated] = $this->ticketGraph($organization, 'NDT-ST-2026-9999');

        $visit = Visit::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id, 'status' => 'canceled', 'timezone' => 'America/Chicago',
        ]);
        $returnVisit = Visit::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id, 'return_of_visit_id' => $visit->id,
            'status' => 'canceled', 'timezone' => 'America/Chicago',
        ]);
        $returnVisit->delete();
        VisitAssignment::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id, 'is_lead' => true,
        ]);
        $workItem = ServiceTicketWorkItem::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'discovered_visit_id' => $visit->id,
            'origin' => 'field_discovered',
            'title' => 'Synthetic purge Work Item',
            'status' => 'completed',
            'created_by_id' => $admin->id,
            'updated_by_id' => $admin->id,
        ]);
        $workItem->visits()->attach($visit->id, [
            'organization_id' => $organization->id,
            'first_touched_by_id' => $admin->id,
            'first_touched_at' => now(),
            'last_touched_by_id' => $admin->id,
            'last_touched_at' => now(),
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1,
            'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved',
        ]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $time = VisitTimeEntry::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
            'user_id' => $admin->id, 'category' => 'on_site', 'started_at' => now()->subHour(),
            'ended_at' => now(), 'source' => 'manual',
        ]);
        $part = VisitPartProposal::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
            'proposed_by_id' => $admin->id, 'description' => 'Test cable', 'quantity' => 1,
            'billing_treatment' => 'billable',
        ]);
        $review = CloseoutReview::query()->create([
            'organization_id' => $organization->id, 'closeout_id' => $closeout->id,
            'reviewer_id' => $admin->id, 'decision' => 'approved', 'self_review_override' => true,
            'decision_token' => (string) Str::uuid(), 'decided_at' => now(),
        ]);

        foreach (['ticket-file', 'visit-photo', 'ack-signature', 'invoice-pdf', 'receipt-pdf'] as $key) {
            Storage::disk('purge-test')->put($key, 'test');
        }
        $signature = CloseoutAcknowledgmentSignature::query()->create([
            'organization_id' => $organization->id, 'closeout_id' => $closeout->id,
            'signer_name' => 'Test POC', 'statement_version' => 'service-closeout-v1',
            'statement_snapshot' => 'Frozen test statement.', 'storage_disk' => 'purge-test',
            'storage_key' => 'ack-signature', 'mime_type' => 'image/png', 'size_bytes' => 4,
            'sha256' => hash('sha256', 'test'), 'signed_at' => now(), 'captured_by_id' => $admin->id,
        ]);
        $file = ServiceTicketFile::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'uploaded_by_id' => $admin->id, 'storage_disk' => 'purge-test', 'storage_key' => 'ticket-file',
            'original_name' => 'field-test.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 4,
        ]);
        ServiceTicketFile::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'uploaded_by_id' => $admin->id, 'storage_disk' => 'purge-test', 'storage_key' => 'already-missing',
            'original_name' => 'already-missing.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 4,
        ]);
        $media = VisitMedia::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
            'uploader_id' => $admin->id, 'storage_disk' => 'purge-test', 'storage_key' => 'visit-photo',
            'mime_type' => 'image/jpeg', 'byte_size' => 4, 'category' => 'after',
        ]);
        ServiceTicketNote::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'author_id' => $admin->id, 'body' => 'Test-only note', 'created_at' => now(),
        ]);

        $project = Project::factory()->create([
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'service_location_id' => $location->id,
        ]);
        DB::table('project_service_ticket')->insert([
            'organization_id' => $organization->id, 'project_id' => $project->id,
            'service_ticket_id' => $ticket->id, 'linked_by_id' => $admin->id, 'linked_at' => now(),
        ]);

        $handoff = BillingHandoff::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'handed_off',
            'created_by_id' => $admin->id,
        ]);
        $invoice = Invoice::query()->create($this->invoiceAttributes($organization, $ticket, $customer, $location, $handoff, $admin));
        $handoff->update(['current_invoice_id' => $invoice->id]);
        InvoiceCloseout::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'visit_id' => $visit->id,
            'closeout_id' => $closeout->id, 'closeout_review_id' => $review->id,
        ]);
        InvoiceLine::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'line_type' => 'labor',
            'description' => 'Test labor', 'quantity_millis' => 1000, 'unit_price_cents' => 10000,
            'subtotal_cents' => 10000, 'total_cents' => 10000, 'source_visit_id' => $visit->id,
            'source_closeout_id' => $closeout->id, 'source_review_id' => $review->id,
            'source_time_entry_id' => $time->id,
        ]);
        InvoiceAcknowledgment::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'contact_name' => 'Test Contact',
            'confirmed' => true, 'presented_by_id' => $admin->id, 'acknowledged_at' => now(),
            'acknowledgment_token' => (string) Str::uuid(),
        ]);
        $provider = PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => (string) Str::uuid(), 'provider' => 'stripe',
            'environment' => 'test', 'enabled' => false,
        ]);
        $attempt = PaymentAttempt::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id,
            'payment_provider_configuration_id' => $provider->id, 'provider' => 'stripe', 'amount_cents' => 10000,
            'status' => 'completed', 'idempotency_key' => (string) Str::uuid(),
            'return_token_hash' => hash('sha256', (string) Str::uuid()), 'initiated_by_id' => $admin->id,
        ]);
        $payment = PaymentTransaction::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id, 'type' => 'payment', 'status' => 'succeeded',
            'provider' => 'stripe', 'method' => 'card', 'payment_source' => 'hosted', 'amount_cents' => 10000,
            'idempotency_key' => (string) Str::uuid(), 'received_at' => now(), 'confirmed_at' => now(),
            'recorded_by_id' => $admin->id,
        ]);
        PaymentReceipt::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id,
            'payment_transaction_id' => $payment->id, 'pdf_status' => 'ready', 'pdf_disk' => 'purge-test',
            'pdf_key' => 'receipt-pdf', 'generated_at' => now(),
        ]);
        AuditEvent::query()->create([
            'organization_id' => $organization->id, 'actor_id' => $admin->id,
            'event_type' => 'field_test.reference', 'subject_type' => ServiceTicket::class,
            'subject_id' => $ticket->id, 'metadata' => ['visit_id' => $visit->id], 'occurred_at' => now(),
        ]);
        AuditEvent::query()->create([
            'organization_id' => $organization->id, 'actor_id' => $admin->id,
            'event_type' => 'field_test.metadata_reference', 'subject_type' => Organization::class,
            'subject_id' => $organization->id, 'metadata' => ['closeout_ids' => [$closeout->id]], 'occurred_at' => now(),
        ]);
        $providerResolver = Mockery::mock(PaymentProviderResolver::class);
        $providerResolver->shouldNotReceive('resolve');
        $this->app->instance(PaymentProviderResolver::class, $providerResolver);

        $response = $this->actingAs($admin)->post(route('office.service-tickets.field-test-purge.destroy', $ticket), [
            'ticket_number' => $ticket->ticket_number, 'acknowledge' => '1',
            'visit_ids' => [$unrelated->id],
        ]);
        $response->assertRedirect(route('office.service-tickets.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('service_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseMissing('visits', ['service_ticket_id' => $ticket->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('payment_transactions', ['id' => $payment->id]);
        $this->assertDatabaseMissing('service_ticket_files', ['id' => $file->id]);
        $this->assertDatabaseMissing('visit_media', ['id' => $media->id]);
        $this->assertDatabaseMissing('closeout_acknowledgment_signatures', ['id' => $signature->id]);
        $this->assertDatabaseMissing('service_ticket_work_items', ['id' => $workItem->id]);
        $this->assertDatabaseMissing('service_ticket_work_item_visit', ['service_ticket_work_item_id' => $workItem->id]);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'field_test.reference']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'field_test.metadata_reference']);
        $this->assertDatabaseHas('service_tickets', ['id' => $unrelated->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('service_locations', ['id' => $location->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('payment_provider_configurations', ['id' => $provider->id]);
        foreach (['ticket-file', 'visit-photo', 'ack-signature', 'invoice-pdf', 'receipt-pdf'] as $key) {
            Storage::disk('purge-test')->assertMissing($key);
        }
        $this->assertDatabaseHas('field_test_purge_cleanups', ['organization_id' => $organization->id, 'status' => 'completed']);

        $this->actingAs($admin)->post("/office/service-tickets/{$ticket->id}/field-test-purge", [
            'ticket_number' => $ticket->ticket_number, 'acknowledge' => '1',
        ])->assertNotFound();
        $this->assertDatabaseHas('service_tickets', ['id' => $unrelated->id]);
    }

    public function test_external_work_item_follow_up_provenance_blocks_target_ticket_purge(): void
    {
        config()->set('field_test.destructive_service_ticket_purge_enabled', true);
        [$organization, $source] = $this->ticketGraph();
        [, $target] = $this->ticketGraph($organization, 'NDT-ST-2026-9998');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $item = ServiceTicketWorkItem::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $source->id,
            'origin' => 'office_added',
            'title' => 'Transferred test work',
            'status' => 'transferred',
            'follow_up_service_ticket_id' => $target->id,
            'created_by_id' => $admin->id,
            'updated_by_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('office.service-tickets.field-test-purge.destroy', $target), [
            'ticket_number' => $target->ticket_number,
            'acknowledge' => '1',
        ])->assertSessionHasErrors('service_ticket');

        $this->assertDatabaseHas('service_tickets', ['id' => $target->id]);
        $this->assertDatabaseHas('service_ticket_work_items', ['id' => $item->id, 'follow_up_service_ticket_id' => $target->id]);
    }

    public function test_storage_failure_is_surfaced_and_cleanup_can_be_retried(): void
    {
        Storage::fake('purge-retry');
        [$organization, $ticket] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        Storage::disk('purge-retry')->put('orphan-after-commit', 'test');
        ServiceTicketFile::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'uploaded_by_id' => $admin->id, 'storage_disk' => 'purge-retry', 'storage_key' => 'orphan-after-commit',
            'original_name' => 'test.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 4,
        ]);

        $filesystemManager = Storage::getFacadeRoot();
        $failedDisk = Mockery::mock();
        $failedDisk->shouldReceive('exists')->with('orphan-after-commit')->andReturn(true);
        $failedDisk->shouldReceive('delete')->with('orphan-after-commit')->andReturn(false);
        Storage::shouldReceive('disk')->with('purge-retry')->andReturn($failedDisk);

        try {
            app(FieldTestServiceTicketPurger::class)->purge($ticket, $admin, $ticket->ticket_number, true);
            $this->fail('Expected storage cleanup failure.');
        } catch (FieldTestPurgeStorageCleanupException) {
            $this->assertDatabaseMissing('service_tickets', ['id' => $ticket->id]);
        }

        $cleanup = FieldTestPurgeCleanup::query()->firstOrFail();
        $this->assertSame('failed', $cleanup->status);
        $this->assertSame(1, $cleanup->failure_count);

        Storage::swap($filesystemManager);
        app(FieldTestServiceTicketPurger::class)->retryCleanup($cleanup);

        Storage::disk('purge-retry')->assertMissing('orphan-after-commit');
        $this->assertSame('completed', $cleanup->fresh()->status);
    }

    public function test_forced_database_failure_rolls_back_the_entire_graph(): void
    {
        [$organization, $ticket] = $this->ticketGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        ServiceTicketNote::query()->create([
            'organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'author_id' => $admin->id, 'body' => 'Must survive rollback', 'created_at' => now(),
        ]);
        $service = new class(app(FieldTestServiceTicketPurgePreview::class)) extends FieldTestServiceTicketPurger
        {
            protected function beforeTicketDelete(ServiceTicket $ticket): void
            {
                throw new RuntimeException('Forced relational failure');
            }
        };

        try {
            $service->purge($ticket, $admin, $ticket->ticket_number, true);
            $this->fail('Expected forced failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced relational failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id]);
        $this->assertDatabaseHas('service_ticket_notes', ['service_ticket_id' => $ticket->id]);
        $this->assertSame(0, FieldTestPurgeCleanup::query()->count());
    }

    /** @return array{Organization, ServiceTicket, Customer, ServiceLocation} */
    private function ticketGraph(?Organization $organization = null, ?string $number = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'timezone' => 'America/Chicago', 'active' => true,
        ]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => $number ?? 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Field-test purge scenario', 'description' => 'Safe synthetic test',
            'priority' => 'normal', 'source' => 'internal', 'status' => 'open',
        ]);

        return [$organization, $ticket, $customer, $location];
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }

    private function invoiceAttributes(
        Organization $organization,
        ServiceTicket $ticket,
        Customer $customer,
        ServiceLocation $location,
        BillingHandoff $handoff,
        User $actor,
    ): array {
        return [
            'organization_id' => $organization->id, 'customer_id' => $customer->id,
            'service_location_id' => $location->id, 'service_ticket_id' => $ticket->id,
            'billing_handoff_id' => $handoff->id, 'generation' => 1, 'invoice_number' => 'NDT-INV-2026-0001',
            'status' => 'issued', 'currency' => 'USD', 'payment_terms' => 'due_on_receipt',
            'billing_name' => $customer->display_name, 'subtotal_cents' => 10000, 'total_cents' => 10000,
            'creation_token' => (string) Str::uuid(), 'issued_at' => now(), 'issued_by_id' => $actor->id,
            'pdf_status' => 'ready', 'pdf_disk' => 'purge-test', 'pdf_key' => 'invoice-pdf',
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ];
    }
}
