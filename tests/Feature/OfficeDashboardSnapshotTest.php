<?php

namespace Tests\Feature;

use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Support\OfficeDashboardSnapshot;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeDashboardSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $ticketSequence = 1;

    private int $invoiceSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-14 03:30:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_uses_scoped_operational_truth_timezone_and_invoice_balance_math(): void
    {
        $organization = Organization::factory()->create(['name' => 'NewDay Test', 'timezone' => 'America/Chicago']);
        [, $membership] = $this->userWithRole('super_admin', $organization);
        [$customer, $location] = $this->customerLocation($organization, 'Visible Customer');

        $noVisitTicket = $this->ticket($organization, $customer, $location, [
            'title' => 'Urgent callback', 'priority' => 'urgent', 'purpose' => 'callback',
        ]);
        $unscheduledTicket = $this->ticket($organization, $customer, $location, ['title' => 'Unscheduled Visit']);
        $this->visit($unscheduledTicket, $location, ['status' => 'planned']);

        $todayTicket = $this->ticket($organization, $customer, $location, ['title' => 'Today service']);
        $todayVisit = $this->visit($todayTicket, $location, [
            'status' => 'pending_closeout',
            'scheduled_start_at' => '2026-08-14 04:30:00',
            'scheduled_end_at' => '2026-08-14 05:00:00',
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $todayVisit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 1,
            'outcome' => 'resolved',
            'submitted_at' => '2026-08-14 02:00:00',
        ]);
        $todayVisit->update(['current_closeout_id' => $closeout->id]);
        BillingHandoff::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $todayTicket->id,
            'visit_id' => $todayVisit->id,
            'closeout_id' => $closeout->id,
            'status' => 'ready',
        ]);

        $outsideTodayTicket = $this->ticket($organization, $customer, $location, ['title' => 'Tomorrow locally']);
        $this->visit($outsideTodayTicket, $location, [
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-08-14 05:30:00',
            'scheduled_end_at' => '2026-08-14 06:30:00',
        ]);
        $this->ticket($organization, $customer, $location, [
            'title' => 'Completed non-billable', 'status' => 'completed', 'billing_disposition' => 'non_billable',
        ]);

        $this->invoice($organization, $customer, $location, 'draft', 2000);
        $this->invoice($organization, $customer, $location, 'ready_for_review', 3000);
        $overdue = $this->invoice($organization, $customer, $location, 'issued', 10000, '2026-08-12');
        $payment = $this->transaction($overdue, 'payment', 4000);
        $this->transaction($overdue, 'refund', 1000, $payment);
        $this->invoice($organization, $customer, $location, 'issued', 7000, '2026-08-20');
        $paid = $this->invoice($organization, $customer, $location, 'issued', 5000, '2026-08-10');
        $this->transaction($paid, 'payment', 5000);
        $this->invoice($organization, $customer, $location, 'void', 9000, '2026-08-01');

        OperationalIncident::query()->create([
            'organization_id' => $organization->id,
            'category' => 'stuck_visit',
            'severity' => 'error',
            'fingerprint' => hash('sha256', 'visible-incident'),
            'status' => 'open',
            'occurrences' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        $other = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$otherCustomer, $otherLocation] = $this->customerLocation($other, 'Hidden Customer');
        $this->ticket($other, $otherCustomer, $otherLocation, ['title' => 'Hidden urgent work', 'priority' => 'urgent']);
        $this->invoice($other, $otherCustomer, $otherLocation, 'issued', 99000, '2026-08-01');
        OperationalIncident::query()->create([
            'organization_id' => $other->id,
            'category' => 'hidden_incident',
            'severity' => 'critical',
            'fingerprint' => hash('sha256', 'hidden-incident'),
            'status' => 'open',
            'occurrences' => 1,
            'first_occurred_at' => now(),
            'last_occurred_at' => now(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(OfficeDashboardSnapshot::class)->for($organization, $membership);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('2026-08-13', $snapshot['local_date']->toDateString());
        $this->assertSame(['tickets' => 1, 'visits' => 1, 'total' => 2], $snapshot['attention']['unscheduled']);
        $this->assertSame(1, $snapshot['attention']['awaiting_review']['count']);
        $this->assertSame(1, $snapshot['attention']['ready_to_invoice']);
        $this->assertSame(['count' => 1, 'amount_cents' => 7000], $snapshot['attention']['overdue']);
        $this->assertSame(1, $snapshot['today']['total']);
        $this->assertSame(1, $snapshot['today']['in_progress']);
        $this->assertSame([$todayVisit->id], $snapshot['today']['visits']->modelKeys());
        $this->assertSame(2, $snapshot['billing']['invoices']['issued_open_count']);
        $this->assertSame(14000, $snapshot['billing']['invoices']['open_ar_cents']);
        $this->assertSame([$overdue->id], $snapshot['billing']['invoices']['oldest_overdue']->modelKeys());
        $this->assertSame([$noVisitTicket->id], $snapshot['follow_up']->pluck('ticket.id')->all());
        $this->assertSame(['Urgent · no Visit', 'Callback'], $snapshot['follow_up']->first()['labels']);
        $this->assertSame(1, $snapshot['health']['open_incidents']);
        $this->assertSame(1, $snapshot['health']['high_incidents']);
        $this->assertLessThanOrEqual(25, $queryCount);
    }

    public function test_snapshot_masks_modules_and_actions_by_existing_capabilities(): void
    {
        $organization = Organization::factory()->create();
        [, $dispatcher] = $this->userWithRole('dispatcher', $organization);
        [, $reviewer] = $this->userWithRole('reviewer', $organization);
        [, $billing] = $this->userWithRole('billing', $organization);
        [, $admin] = $this->userWithRole('super_admin', $organization);
        $service = app(OfficeDashboardSnapshot::class);

        $dispatcherSnapshot = $service->for($organization, $dispatcher);
        $this->assertNotNull($dispatcherSnapshot['today']);
        $this->assertNotNull($dispatcherSnapshot['attention']['awaiting_review']);
        $this->assertNull($dispatcherSnapshot['billing']);
        $this->assertNull($dispatcherSnapshot['health']);
        $this->assertContains('New Service Ticket', collect($dispatcherSnapshot['actions'])->pluck('label'));

        $reviewerSnapshot = $service->for($organization, $reviewer);
        $this->assertNotNull($reviewerSnapshot['attention']['awaiting_review']);
        $this->assertNotNull($reviewerSnapshot['billing']['invoices']);
        $this->assertNull($reviewerSnapshot['billing']['ready_to_invoice']);
        $this->assertNull($reviewerSnapshot['health']);

        $billingSnapshot = $service->for($organization, $billing);
        $this->assertNotNull($billingSnapshot['billing']);
        $this->assertNull($billingSnapshot['attention']['awaiting_review']);
        $this->assertNull($billingSnapshot['health']);
        $this->assertNotContains('New Service Ticket', collect($billingSnapshot['actions'])->pluck('label'));
        $this->assertContains('New Invoice', collect($billingSnapshot['actions'])->pluck('label'));

        $adminSnapshot = $service->for($organization, $admin);
        $this->assertNotNull($adminSnapshot['today']);
        $this->assertNotNull($adminSnapshot['billing']);
        $this->assertNotNull($adminSnapshot['health']);
    }

    public function test_office_home_renders_real_dashboard_and_hides_unauthorized_sections(): void
    {
        $organization = Organization::factory()->create(['name' => 'Dashboard Organization']);
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);
        [$billing] = $this->userWithRole('billing', $organization);

        $this->actingAs($dispatcher)->get('/office')
            ->assertOk()
            ->assertSee('NewDay Home')
            ->assertSee('Search Customers, Contacts, and Service Locations')
            ->assertSee('Service Operations')
            ->assertSee('Projects, Tasks &amp; Milestones', false)
            ->assertSee('Today’s Visits')
            ->assertSee('Awaiting Review')
            ->assertSee('New Service Ticket')
            ->assertDontSee('Billing &amp; Collections', false)
            ->assertDontSee('System Health')
            ->assertDontSee('Phase 1')
            ->assertDontSee('Foundation active')
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('aria-current="page"', false);

        $this->actingAs($billing)->get('/office')
            ->assertOk()
            ->assertSee('NewDay Home')
            ->assertSee('Billing &amp; Collections', false)
            ->assertSee('New Invoice')
            ->assertDontSee('Awaiting Review')
            ->assertDontSee('System Health');
    }

    public function test_inactive_membership_cannot_open_dashboard(): void
    {
        $organization = Organization::factory()->create();
        [$user, $membership] = $this->userWithRole('super_admin', $organization);
        $membership->update(['status' => 'inactive']);

        $this->actingAs($user)->get('/office')->assertForbidden();
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership->fresh()];
    }

    private function customerLocation(Organization $organization, string $name): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => $name]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'timezone' => $organization->timezone,
        ]);

        return [$customer, $location];
    }

    private function ticket(Organization $organization, Customer $customer, ServiceLocation $location, array $attributes = []): ServiceTicket
    {
        return ServiceTicket::query()->create(array_merge([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => sprintf('NDT-ST-2026-%04d', $this->ticketSequence++),
            'title' => 'Dashboard service',
            'description' => 'Dashboard fixture',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
            'status' => 'open',
            'next_visit_number' => 1,
        ], $attributes));
    }

    private function visit(ServiceTicket $ticket, ServiceLocation $location, array $attributes = []): Visit
    {
        return Visit::query()->create(array_merge([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'ticket_visit_number' => $ticket->visits()->withTrashed()->count() + 1,
            'service_location_id' => $location->id,
            'status' => 'planned',
            'timezone' => $location->timezone,
        ], $attributes));
    }

    private function invoice(
        Organization $organization,
        Customer $customer,
        ServiceLocation $location,
        string $status,
        int $totalCents,
        ?string $dueOn = null,
    ): Invoice {
        return Invoice::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'generation' => 1,
            'invoice_number' => sprintf('NDT-INV-2026-%04d', $this->invoiceSequence++),
            'status' => $status,
            'currency' => 'USD',
            'payment_terms' => 'due_on_receipt',
            'due_on' => $dueOn,
            'billing_name' => $customer->display_name,
            'seller_name' => $organization->name,
            'subtotal_cents' => $totalCents,
            'total_cents' => $totalCents,
            'creation_token' => (string) Str::uuid(),
            'issued_at' => $status === 'issued' ? now() : null,
            'pdf_status' => $status === 'issued' ? 'pending' : 'not_requested',
        ]);
    }

    private function transaction(Invoice $invoice, string $type, int $amountCents, ?PaymentTransaction $original = null): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'original_transaction_id' => $original?->id,
            'type' => $type,
            'status' => 'succeeded',
            'method' => 'cash',
            'amount_cents' => $amountCents,
            'idempotency_key' => (string) Str::uuid(),
            'received_at' => now(),
            'confirmed_at' => now(),
        ]);
    }
}
