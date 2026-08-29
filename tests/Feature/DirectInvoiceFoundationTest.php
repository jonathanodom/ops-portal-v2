<?php

namespace Tests\Feature;

use App\Domain\InvoiceWorkflow;
use App\Domain\UnissuedInvoiceDeletionWorkflow;
use App\Jobs\RenderInvoicePdf;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\IncidentRecorder;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DirectInvoiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_direct_draft_uses_normal_numbering_and_canonical_customer_location_snapshots(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $preferred = Contact::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Preferred Customer Contact',
            'email' => 'preferred@example.test',
            'is_preferred' => true,
        ]);
        $locationContact = Contact::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Site Billing Contact',
            'email' => 'site@example.test',
        ]);
        $location->update(['primary_contact_id' => $locationContact->id]);
        $token = (string) Str::uuid();

        $invoice = app(InvoiceWorkflow::class)->createDirect(
            $organization,
            $customer->id,
            $location->id,
            null,
            $admin,
            $token,
        );
        $retry = app(InvoiceWorkflow::class)->createDirect($organization, $customer->id, $location->id, null, $admin, $token);

        $this->assertTrue($invoice->isDirect());
        $this->assertSame($invoice->id, $retry->id);
        $this->assertSame('NDT-INV-'.now($organization->timezone)->year.'-0001', $invoice->invoice_number);
        $this->assertNull($invoice->service_ticket_id);
        $this->assertNull($invoice->billing_handoff_id);
        $this->assertSame($customer->display_name, $invoice->billing_name);
        $this->assertSame($customer->legal_name, $invoice->billing_legal_name);
        $this->assertSame('Site Billing Contact', $invoice->billing_contact_name);
        $this->assertSame('site@example.test', $invoice->billing_email);
        $this->assertSame($location->address_line_1, $invoice->billing_address_line_1);
        $this->assertSame($organization->name, $invoice->seller_name);
        $this->assertSame(1, Invoice::query()->count());
        $this->assertDatabaseCount('billing_handoffs', 0);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'display_name' => $customer->display_name]);
        $this->assertDatabaseHas('service_locations', ['id' => $location->id, 'customer_id' => $customer->id]);
        $this->assertSame(1, DocumentSequence::query()->where('document_type', 'invoice')->value('current_value'));
        $event = AuditEvent::query()->where('event_type', 'invoice.direct_created')->firstOrFail();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($customer->id, $event->metadata['customer_id']);
        $this->assertSame($locationContact->id, $event->metadata['contact_id']);
        $this->assertSame('direct', $event->metadata['source_type']);
        $this->assertDatabaseHas('contacts', ['id' => $preferred->id]);
    }

    public function test_direct_draft_rejects_inactive_mismatched_and_cross_organization_records_before_numbering(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        [$foreignOrganization, , $foreignCustomer, $foreignLocation] = $this->scenario();
        $foreignContact = Contact::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'customer_id' => $foreignCustomer->id,
        ]);

        foreach ([
            [$foreignCustomer->id, $foreignLocation->id, null, 'customer_id'],
            [$customer->id, $foreignLocation->id, null, 'service_location_id'],
            [$customer->id, $location->id, $foreignContact->id, 'contact_id'],
        ] as [$customerId, $locationId, $contactId, $error]) {
            try {
                app(InvoiceWorkflow::class)->createDirect($organization, $customerId, $locationId, $contactId, $admin, (string) Str::uuid());
                $this->fail('Invalid direct-invoice source selection should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($error, $exception->errors());
            }
        }

        $customer->update(['status' => 'inactive']);
        try {
            app(InvoiceWorkflow::class)->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
            $this->fail('Inactive customer should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertSame(0, Invoice::query()->forOrganization($organization->id)->count());
        $this->assertFalse(DocumentSequence::query()->where('organization_id', $organization->id)->where('document_type', 'invoice')->exists());
    }

    public function test_direct_invoice_uses_existing_edit_issue_pdf_and_void_reissue_lifecycle(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        Queue::fake();
        Storage::fake('local');
        $workflow = app(InvoiceWorkflow::class);
        $invoice = $workflow->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
        $workflow->addLine($invoice, $admin, [
            'line_type' => 'equipment',
            'description' => 'Network appliance',
            'quantity_millis' => 1000,
            'unit' => 'each',
            'unit_price_cents' => 25000,
            'included' => true,
            'billing_treatment' => 'billable',
            'taxable' => false,
        ]);

        $workflow->markReady($invoice, $admin);
        $issued = $workflow->issue($invoice, $admin, (string) Str::uuid());
        $this->assertSame('issued', $issued->status);
        $this->assertSame(25000, $issued->total_cents);
        Queue::assertPushed(RenderInvoicePdf::class);
        (new RenderInvoicePdf($issued->id))->handle(app(IncidentRecorder::class));
        $this->assertSame('ready', $issued->fresh()->pdf_status);
        Storage::disk('local')->assertExists($issued->fresh()->pdf_key);

        $this->actingAs($admin)->get('/office/invoices')->assertOk()->assertSee('Direct invoice');
        $this->actingAs($admin)->get("/office/invoices/{$issued->id}")->assertOk()->assertSee('Direct invoice');
        $this->actingAs($admin)->get("/invoices/{$issued->id}/present")->assertOk()->assertSee('Direct invoice');

        $replacement = $workflow->voidAndReissue($issued->fresh(), $admin, 'Correct customer-facing description.', (string) Str::uuid());
        $this->assertTrue($replacement->isDirect());
        $this->assertSame('draft', $replacement->status);
        $this->assertSame($issued->id, $replacement->reissue_of_invoice_id);
        $this->assertSame(2, $replacement->generation);
        $this->assertNull($replacement->billing_handoff_id);
        $this->assertDatabaseHas('invoices', ['id' => $issued->id, 'status' => 'void']);
    }

    public function test_unissued_direct_invoice_deletion_preserves_customer_and_durable_audit_history(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $invoice = app(InvoiceWorkflow::class)->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
        app(InvoiceWorkflow::class)->addLine($invoice, $admin, [
            'line_type' => 'other', 'description' => 'Temporary line', 'quantity_millis' => 1000,
            'unit_price_cents' => 5000, 'included' => true, 'taxable' => false,
        ]);

        $handoff = app(UnissuedInvoiceDeletionWorkflow::class)->delete($invoice, $admin, 'Created for the wrong customer request.');

        $this->assertNull($handoff);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $event = AuditEvent::query()->where('event_type', 'invoice.unissued_deleted')->firstOrFail();
        $this->assertSame($customer->getMorphClass(), $event->subject_type);
        $this->assertSame($customer->id, $event->subject_id);
        $this->assertNull($event->metadata['billing_handoff_id']);
        $this->assertNull($event->metadata['service_ticket_id']);
    }

    public function test_direct_invoice_creation_rolls_back_number_and_draft_when_audit_write_fails(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));

        try {
            app(InvoiceWorkflow::class)->createDirect($organization, $customer->id, $location->id, null, $admin, (string) Str::uuid());
            $this->fail('The audit failure should roll back direct invoice creation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('invoices', 0);
        $this->assertFalse(DocumentSequence::query()->where('organization_id', $organization->id)->where('document_type', 'invoice')->exists());
    }

    /** @return array{Organization, User, Customer, ServiceLocation} */
    private function scenario(): array
    {
        $organization = Organization::factory()->create([
            'name' => 'NewDay Tech',
            'legal_name' => 'NewDay Tech LLC',
            'email' => 'billing@newdaytech.net',
            'phone' => '555-0100',
            'address_line_1' => '100 Service Way',
            'city' => 'Dallas',
            'state' => 'TX',
            'postal_code' => '75001',
            'timezone' => 'America/Chicago',
        ]);
        $admin = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $admin->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());
        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'display_name' => 'Direct Billing Customer',
            'legal_name' => 'Direct Billing Customer LLC',
            'email' => 'customer@example.test',
        ]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Customer Main Office',
            'timezone' => 'America/Chicago',
        ]);
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_currency' => 'USD',
            'default_payment_terms' => 'due_on_receipt',
            'default_tax_rate_basis_points' => 0,
        ]);

        return [$organization, $admin, $customer, $location];
    }
}
