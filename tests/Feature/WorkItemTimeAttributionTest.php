<?php

namespace Tests\Feature;

use App\Domain\FieldExecution;
use App\Domain\ServiceTicketWorkItemWorkflow;
use App\Domain\VisitTimeAllocationWorkflow;
use App\Domain\WorkItemTimeAttribution;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class WorkItemTimeAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_field_timer_captures_work_item_and_switches_with_exact_adjacency(): void
    {
        [$organization, $ticket, $visit, $technician, $closeout] = $this->graph('technician');
        $item = $this->item($ticket, $technician);
        $flow = app(FieldExecution::class);
        $primary = $flow->startTimer($visit, $closeout, $technician, 'on_site');
        $work = $flow->switchWorkFocus($visit, $technician, $item);

        $this->assertTrue($primary->fresh()->ended_at->equalTo($work->started_at));
        $this->assertSame($item->id, $work->service_ticket_work_item_id);
        $this->assertSame($technician->id, $work->active_user_id);
        $this->assertDatabaseHas('service_ticket_work_item_visit', ['service_ticket_work_item_id' => $item->id, 'visit_id' => $visit->id]);
        $this->assertDatabaseHas('audit_events', ['organization_id' => $organization->id, 'event_type' => 'visit_time.work_focus_switched']);

        $same = $flow->switchWorkFocus($visit, $technician, $item);
        $this->assertSame($work->id, $same->id);
        $this->assertDatabaseCount('visit_time_entries', 2);
        $back = $flow->switchWorkFocus($visit, $technician, null);
        $this->assertNull($back->service_ticket_work_item_id);
        $this->assertTrue($work->fresh()->ended_at->equalTo($back->started_at));
    }

    public function test_travel_and_cross_ticket_targets_are_rejected(): void
    {
        [, $ticket, $visit, $technician, $closeout] = $this->graph('technician');
        $item = $this->item($ticket, $technician);
        try {
            app(FieldExecution::class)->startTimer($visit, $closeout, $technician, 'travel', $item);
            $this->fail('Travel attribution should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('work_item', $e->errors());
        }
        [$otherOrganization, $otherTicket, , $otherUser] = $this->graph('technician');
        $other = $this->item($otherTicket, $otherUser);
        $this->expectException(NotFoundHttpException::class);
        app(FieldExecution::class)->startTimer($visit, $closeout, $technician, 'on_site', $other);
    }

    public function test_active_attributed_timer_blocks_work_item_disposition(): void
    {
        [, $ticket, $visit, $technician, $closeout] = $this->graph('technician');
        $item = $this->item($ticket, $technician);
        app(FieldExecution::class)->startTimer($visit, $closeout, $technician, 'on_site', $item);

        $this->expectException(ValidationException::class);
        app(ServiceTicketWorkItemWorkflow::class)->updateFromField($item, $visit, $technician, ['status' => 'completed']);
    }

    public function test_exact_super_admin_creates_partial_immutable_allocations_and_projection_uses_latest(): void
    {
        [, $ticket, $visit, $admin, $closeout] = $this->graph('super_admin');
        $item = $this->item($ticket, $admin);
        $entry = $this->endedEntry($visit, $closeout, $admin, 7200);
        $workflow = app(VisitTimeAllocationWorkflow::class);
        $first = $workflow->allocate($entry, $admin, [
            ['work_item_id' => null, 'allocated_seconds' => 1800],
            ['work_item_id' => $item->id, 'allocated_seconds' => 1800],
        ], 'Split factual block');
        $projection = app(WorkItemTimeAttribution::class)->forEntry($entry->fresh()->load(['workItem', 'allocationSets.allocations.workItem']));
        $this->assertSame(3600, $projection['allocated_seconds']);
        $this->assertSame(3600, $projection['unallocated_seconds']);
        $this->assertSame(1, $first->sequence);

        $second = $workflow->allocate($entry, $admin, [['work_item_id' => $item->id, 'allocated_seconds' => 5400]], 'Refined allocation');
        $this->assertSame(2, $second->sequence);
        $this->assertDatabaseCount('visit_time_allocation_sets', 2);
        $projection = app(WorkItemTimeAttribution::class)->forEntry($entry->fresh()->load(['workItem', 'allocationSets.allocations.workItem']));
        $this->assertSame(5400, $projection['allocated_seconds']);
        $this->assertSame(1800, $projection['unallocated_seconds']);
    }

    public function test_allocation_gate_requires_exact_super_admin_and_explicit_capability(): void
    {
        [$organization, $ticket, $visit, $dispatcher, $closeout] = $this->graph('dispatcher');
        $item = $this->item($ticket, $dispatcher);
        $entry = $this->endedEntry($visit, $closeout, $dispatcher, 3600);
        $capability = Capability::query()->where('key', VisitTimeAllocationWorkflow::CAPABILITY)->firstOrFail();
        $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $dispatcher->id)->firstOrFail();
        $membership->capabilityOverrides()->attach($capability->id, ['effect' => 'grant']);
        $this->expectException(HttpException::class);
        app(VisitTimeAllocationWorkflow::class)->allocate($entry, $dispatcher, [['work_item_id' => $item->id, 'allocated_seconds' => 1800]], 'Not a super admin');
    }

    public function test_explicit_capability_denial_blocks_super_admin_allocation(): void
    {
        [$organization, $ticket, $visit, $admin, $closeout] = $this->graph('super_admin');
        $item = $this->item($ticket, $admin);
        $entry = $this->endedEntry($visit, $closeout, $admin, 3600);
        $capability = Capability::query()->where('key', VisitTimeAllocationWorkflow::CAPABILITY)->firstOrFail();
        $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $admin->id)->firstOrFail();
        $membership->capabilityOverrides()->attach($capability->id, ['effect' => 'deny']);

        $this->expectException(HttpException::class);
        app(VisitTimeAllocationWorkflow::class)->allocate($entry, $admin, [['work_item_id' => $item->id, 'allocated_seconds' => 1800]], 'Denied allocation');
    }

    public function test_allocation_does_not_change_handoff_or_invoice_financials(): void
    {
        [, $ticket, $visit, $admin, $closeout] = $this->graph('super_admin');
        $item = $this->item($ticket, $admin);
        $entry = $this->endedEntry($visit, $closeout, $admin, 5400);
        $handoff = BillingHandoff::query()->create([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'status' => 'ready',
            'approved_time_minutes' => 90,
            'approved_parts_count' => 2,
            'created_by_id' => $admin->id,
        ]);
        $invoice = Invoice::query()->create([
            'organization_id' => $ticket->organization_id,
            'customer_id' => $ticket->customer_id,
            'service_location_id' => $ticket->service_location_id,
            'service_ticket_id' => $ticket->id,
            'billing_handoff_id' => $handoff->id,
            'invoice_number' => 'NDT-INV-2026-ATTR-'.$ticket->organization_id,
            'status' => 'draft',
            'currency' => 'USD',
            'payment_terms' => 'due_on_receipt',
            'billing_name' => $ticket->customer->display_name,
            'subtotal_cents' => 12345,
            'discount_total_cents' => 345,
            'tax_total_cents' => 990,
            'total_cents' => 12990,
            'creation_token' => (string) Str::uuid(),
            'created_by_id' => $admin->id,
            'updated_by_id' => $admin->id,
        ]);

        app(VisitTimeAllocationWorkflow::class)->allocate($entry, $admin, [
            ['work_item_id' => null, 'allocated_seconds' => 1800],
            ['work_item_id' => $item->id, 'allocated_seconds' => 2700],
        ], 'Operational split only');

        $this->assertSame([
            'status' => 'ready',
            'approved_time_minutes' => 90,
            'approved_parts_count' => 2,
        ], $handoff->fresh()->only(['status', 'approved_time_minutes', 'approved_parts_count']));
        $this->assertSame([12345, 345, 990, 12990], array_values($invoice->fresh()->only([
            'subtotal_cents', 'discount_total_cents', 'tax_total_cents', 'total_cents',
        ])));
        $this->assertSame(5400, $entry->fresh()->effectiveDurationSeconds());
    }

    public function test_correction_cannot_shrink_below_current_allocation(): void
    {
        [, $ticket, $visit, $admin, $closeout] = $this->graph('super_admin');
        $item = $this->item($ticket, $admin);
        $entry = $this->endedEntry($visit, $closeout, $admin, 7200);
        app(VisitTimeAllocationWorkflow::class)->allocate($entry, $admin, [['work_item_id' => $item->id, 'allocated_seconds' => 5400]], 'Operational allocation');
        $this->expectException(ValidationException::class);
        app(FieldExecution::class)->correctTime($entry, $admin, now()->subHour(), now(), 'Shorten interval');
    }

    private function item(ServiceTicket $ticket, User $actor): ServiceTicketWorkItem
    {
        return app(ServiceTicketWorkItemWorkflow::class)->createFromOffice($ticket, $actor, ['title' => 'Additional device work', 'status' => 'open']);
    }

    private function endedEntry(Visit $visit, Closeout $closeout, User $user, int $seconds): VisitTimeEntry
    {
        return VisitTimeEntry::query()->create(['organization_id' => $visit->organization_id, 'visit_id' => $visit->id,
            'closeout_id' => $closeout->id, 'user_id' => $user->id, 'category' => 'on_site',
            'started_at' => now()->subSeconds($seconds), 'ended_at' => now(), 'source' => 'manual']);
    }

    private function graph(string $role): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id,
            'service_location_id' => $location->id, 'ticket_number' => 'ATTR-'.$organization->id, 'title' => 'Attribution test',
            'priority' => 'normal', 'source' => 'internal', 'purpose' => 'service_call', 'billing_disposition' => 'billable', 'status' => 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id, 'status' => 'on_site', 'timezone' => 'America/Chicago']);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id, 'is_lead' => true]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id,
            'version' => 1, 'status' => 'draft', 'content_version' => 1]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return [$organization, $ticket, $visit->fresh(), $user, $closeout];
    }
}
