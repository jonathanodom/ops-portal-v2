<?php

namespace Tests\Feature;

use App\Domain\ReturnFollowUpCreator;
use App\Jobs\DeliverPortalNotificationEmail;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TicketScheduleNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Queue::fake();
    }

    public function test_initial_schedule_and_assignment_emit_one_distinct_event_each_for_the_technician(): void
    {
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician');

        $this->saveSchedule($dispatcher, $visit, [$membership->id], '2026-09-08T10:00', '2026-09-08T11:00')
            ->assertRedirect();

        $scheduled = PortalNotificationEvent::query()->where('event_key', 'ticket.scheduled')->with('recipients')->sole();
        $this->assertSame('Job Scheduled', $scheduled->title);
        $this->assertStringContainsString('September 8 at 10:00 AM–11:00 AM CDT', $scheduled->body);
        $this->assertSame('/field/visits/'.$visit->id, $scheduled->action_url);
        $this->assertSame([$technician->id], $scheduled->recipients->pluck('user_id')->all());
        $this->assertDatabaseCount('portal_notification_events', 2);
        $this->assertDatabaseHas('portal_notification_events', ['event_key' => 'ticket.assigned']);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
    }

    public function test_no_op_save_emits_none_and_meaningful_change_emits_one_reschedule(): void
    {
        [$organization, $dispatcher, $visit] = $this->graph();
        [, $membership] = $this->member($organization, 'technician');
        $this->saveSchedule($dispatcher, $visit, [$membership->id], '2026-09-08T10:00', '2026-09-08T11:00');

        $this->saveSchedule($dispatcher, $visit, [$membership->id], '2026-09-08T10:00', '2026-09-08T11:00')
            ->assertRedirect();
        $this->assertSame(0, PortalNotificationEvent::query()->where('event_key', 'ticket.rescheduled')->count());
        $this->assertDatabaseCount('portal_notification_events', 2);

        $this->saveSchedule($dispatcher, $visit, [$membership->id], '2026-09-09T13:00', '2026-09-09T14:30')
            ->assertRedirect();

        $rescheduled = PortalNotificationEvent::query()->where('event_key', 'ticket.rescheduled')->sole();
        $this->assertStringContainsString('Previous: September 8 at 10:00 AM–11:00 AM CDT', $rescheduled->body);
        $this->assertStringContainsString('New: September 9 at 1:00 PM–2:30 PM CDT', $rescheduled->body);
        $this->assertSame('ST-9001 has been rescheduled. Open Ops Portal to review.', $rescheduled->metadata['push_body']);
        $this->assertDatabaseCount('portal_notification_events', 3);
    }

    public function test_unassigned_schedule_succeeds_without_arbitrary_recipients(): void
    {
        [, $dispatcher, $visit] = $this->graph();

        $this->saveSchedule($dispatcher, $visit, [], '2026-09-08T10:00', '2026-09-08T11:00')
            ->assertRedirect();

        $this->assertSame('scheduled', $visit->fresh()->status);
        $this->assertDatabaseCount('portal_notification_events', 0);
    }

    public function test_schedule_removal_notifies_the_previously_assigned_technician_once(): void
    {
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician');
        $this->saveSchedule($dispatcher, $visit, [$membership->id], '2026-09-08T10:00', '2026-09-08T11:00');

        $this->saveSchedule($dispatcher, $visit, [], null, null)->assertRedirect();

        $event = PortalNotificationEvent::query()->where('event_key', 'ticket.unscheduled')->with('recipients')->sole();
        $this->assertSame([$technician->id], $event->recipients->pluck('user_id')->all());
        $this->assertSame('planned', $visit->fresh()->status);
    }

    public function test_return_follow_up_creation_notifies_dispatch_once_and_links_to_the_child(): void
    {
        [$organization, $dispatcher, $visit] = $this->graph('pending_closeout');
        [$technician] = $this->member($organization, 'technician');
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 1,
            'outcome' => 'needs_return_trip',
            'return_reason' => 'Lift required',
            'unfinished_work' => 'Install final exterior camera',
            'needed_equipment' => '35-foot lift',
            'submitted_by_id' => $technician->id,
            'submitted_at' => now(),
        ]);

        $creator = app(ReturnFollowUpCreator::class);
        $first = DB::transaction(fn () => $creator->create($closeout, $technician));
        $second = DB::transaction(fn () => $creator->create($closeout, $technician));

        $this->assertTrue($first->is($second));
        $event = PortalNotificationEvent::query()->where('event_key', 'return_followup.created')->with('recipients')->sole();
        $this->assertSame('/office/service-tickets/'.$first->id, $event->action_url);
        $this->assertSame([$dispatcher->id], $event->recipients->pluck('user_id')->all());
        $this->assertSame('A return follow-up needs review in Ops Portal.', $event->metadata['push_body']);
        $this->assertDatabaseCount('service_tickets', 2);
        $this->assertDatabaseCount('portal_notification_events', 1);
    }

    public function test_follow_up_scheduling_uses_generic_events_and_chained_returns_notify_once_each(): void
    {
        [$organization, $dispatcher, $sourceVisit] = $this->graph('pending_closeout');
        [$technician, $membership] = $this->member($organization, 'technician');
        $firstCloseout = $this->returnCloseout($sourceVisit, $technician);
        $creator = app(ReturnFollowUpCreator::class);
        $firstFollowUp = DB::transaction(fn () => $creator->create($firstCloseout, $technician));
        $followUpVisit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $firstFollowUp->id,
            'service_location_id' => $firstFollowUp->service_location_id,
            'status' => 'planned',
            'timezone' => 'America/Chicago',
        ]);

        $this->saveSchedule($dispatcher, $followUpVisit, [$membership->id], '2026-09-12T13:00', '2026-09-12T15:00')
            ->assertRedirect();

        $this->assertDatabaseHas('portal_notification_events', [
            'event_key' => 'ticket.assigned',
            'related_id' => $firstFollowUp->id,
        ]);
        $this->assertDatabaseHas('portal_notification_events', [
            'event_key' => 'ticket.scheduled',
            'related_id' => $firstFollowUp->id,
        ]);
        $this->assertSame(0, PortalNotificationEvent::query()->where('event_key', 'return_followup.assigned')->count());
        $this->assertSame(0, PortalNotificationEvent::query()->where('event_key', 'return_followup.scheduled')->count());

        $followUpVisit->update(['status' => 'pending_closeout']);
        $secondCloseout = $this->returnCloseout($followUpVisit, $technician);
        $secondFollowUp = DB::transaction(fn () => $creator->create($secondCloseout, $technician));

        $this->assertSame($firstFollowUp->id, $secondFollowUp->return_follow_up_source_ticket_id);
        $this->assertSame(2, PortalNotificationEvent::query()->where('event_key', 'return_followup.created')->count());
    }

    /** @return array{Organization, User, Visit} */
    private function graph(string $visitStatus = 'planned'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$dispatcher] = $this->member($organization, 'dispatcher');
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Main Site',
            'address_line_1' => '100 Main Street',
            'city' => 'Fort Worth',
            'state' => 'TX',
            'postal_code' => '76102',
            'timezone' => 'America/Chicago',
            'is_primary' => true,
            'active' => true,
        ]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'ST-9001',
            'title' => 'Camera Installation',
            'priority' => 'normal',
            'source' => 'phone',
            'purpose' => 'installation_project',
            'billing_disposition' => 'billable',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => $visitStatus,
            'timezone' => 'America/Chicago',
        ]);

        return [$organization, $dispatcher, $visit];
    }

    /** @return array{User, OrganizationMembership} */
    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }

    private function saveSchedule(User $actor, Visit $visit, array $memberships, ?string $start, ?string $end)
    {
        return $this->actingAs($actor)->put(route('office.visits.update', $visit), [
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'assignees' => $memberships,
        ]);
    }

    private function returnCloseout(Visit $visit, User $technician): Closeout
    {
        return Closeout::query()->create([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 1,
            'outcome' => 'needs_return_trip',
            'return_reason' => 'Additional access required',
            'unfinished_work' => 'Complete remaining work',
            'needed_equipment' => 'Lift',
            'submitted_by_id' => $technician->id,
            'submitted_at' => now(),
        ]);
    }
}
