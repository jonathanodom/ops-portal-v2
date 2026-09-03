<?php

namespace Tests\Feature;

use App\Domain\Notifications\TicketAssignedNotifier;
use App\Jobs\DeliverPortalNotificationEmail;
use App\Jobs\DeliverPortalNotificationPush;
use App\Models\BrowserPushSubscription;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_initial_assignment_notifies_the_new_user_through_enabled_channels_and_deep_links_to_visit(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician');

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();

        $event = PortalNotificationEvent::query()->where('event_key', 'ticket.assigned')->with('recipients')->sole();
        $this->assertSame('ticket.assigned', $event->event_key);
        $this->assertSame('New Job Assignment — '.$visit->serviceTicket->ticket_number, $event->title);
        $this->assertSame('/field/visits/'.$visit->id, $event->action_url);
        $this->assertSame($visit->service_ticket_id, $event->related_id);
        $this->assertSame($dispatcher->id, $event->actor_id);
        $this->assertSame([$technician->id], $event->recipients->pluck('user_id')->all());
        $this->assertSame(['in_app', 'email', 'push'], $event->recipients->sole()->channels);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
        Queue::assertNotPushed(DeliverPortalNotificationPush::class);

        $this->actingAs($technician)->getJson(route('notifications.recent'))
            ->assertOk()
            ->assertJsonPath('notifications.0.title', $event->title);

        $auditId = (int) $event->metadata['assignment_event_id'];
        $replay = app(TicketAssignedNotifier::class)->notify($visit, [$membership->id], $dispatcher, $auditId);
        $this->assertSame($event->id, $replay->id);
        $this->assertDatabaseCount('portal_notification_events', 2);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
    }

    public function test_same_crew_save_and_unassignment_do_not_create_new_assignment_notifications(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [, $membership] = $this->member($organization, 'technician');

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();
        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();
        $this->assign($dispatcher, $visit, [])->assertRedirect();

        $this->assertDatabaseCount('portal_notification_events', 2);
        $this->assertDatabaseCount('portal_notification_recipients', 2);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
    }

    public function test_reassignment_notifies_only_the_new_crew_member(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [$first, $firstMembership] = $this->member($organization, 'technician');
        [$second, $secondMembership] = $this->member($organization, 'technician');

        $this->assign($dispatcher, $visit, [$firstMembership->id])->assertRedirect();
        $this->assign($dispatcher, $visit, [$secondMembership->id])->assertRedirect();

        $events = PortalNotificationEvent::query()->where('event_key', 'ticket.assigned')->with('recipients')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame([$first->id], $events[0]->recipients->pluck('user_id')->all());
        $this->assertSame([$second->id], $events[1]->recipients->pluck('user_id')->all());
        $this->assertDatabaseCount('portal_notification_events', 3);
        $this->assertDatabaseCount('portal_notification_recipients', 3);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 3);
    }

    public function test_multiple_devices_create_push_deliveries_without_duplicate_app_or_email_records(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician');
        $this->subscription($organization, $technician, 'desktop');
        $this->subscription($organization, $technician, 'phone');

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();

        $this->assertDatabaseCount('portal_notification_events', 2);
        $this->assertDatabaseCount('portal_notification_recipients', 2);
        $this->assertDatabaseCount('portal_notification_push_deliveries', 4);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
        Queue::assertPushed(DeliverPortalNotificationPush::class, 4);
    }

    public function test_return_follow_up_visit_uses_the_same_assignment_event(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $sourceVisit] = $this->graph();
        [, $membership] = $this->member($organization, 'technician');
        $source = $sourceVisit->serviceTicket;
        $followUp = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $source->customer_id,
            'service_location_id' => $source->service_location_id,
            'ticket_number' => 'NDT-ST-2026-9002',
            'title' => 'Return Visit — '.$source->title,
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_visit',
            'status' => 'open',
            'return_follow_up_source_ticket_id' => $source->id,
            'return_follow_up_status' => 'ready_to_schedule',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $followUp->id,
            'service_location_id' => $source->service_location_id,
            'status' => 'planned',
            'timezone' => 'America/Chicago',
        ]);

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();

        $event = PortalNotificationEvent::query()->where('event_key', 'ticket.assigned')->sole();
        $this->assertSame('ticket.assigned', $event->event_key);
        $this->assertSame($followUp->id, $event->related_id);
        $this->assertDatabaseCount('portal_notification_events', 2);
    }

    public function test_failed_or_unauthorized_assignment_creates_no_notification(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician');
        [, $foreignMembership] = $this->member(Organization::factory()->create(), 'technician');

        $this->assign($dispatcher, $visit, [$foreignMembership->id])->assertSessionHasErrors('assignees');
        $this->actingAs($technician)->put(route('office.visits.update', $visit), $this->assignmentPayload([$membership->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('visit_assignments', 0);
        $this->assertDatabaseCount('portal_notification_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_notification_publication_failure_does_not_roll_back_assignment(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [, $membership] = $this->member($organization, 'technician');
        Schema::drop('portal_notification_events');
        Log::spy();

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();

        $this->assertDatabaseHas('visit_assignments', [
            'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id,
        ]);
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_missing_email_skips_email_but_keeps_in_app_and_push_delivery(): void
    {
        Queue::fake();
        [$organization, $dispatcher, $visit] = $this->graph();
        [$technician, $membership] = $this->member($organization, 'technician', '');
        $this->subscription($organization, $technician, 'phone');

        $this->assign($dispatcher, $visit, [$membership->id])->assertRedirect();

        $this->assertDatabaseCount('portal_notification_recipients', 2);
        $this->assertDatabaseCount('portal_notification_push_deliveries', 2);
        Queue::assertNotPushed(DeliverPortalNotificationEmail::class);
        Queue::assertPushed(DeliverPortalNotificationPush::class, 2);
    }

    /** @return array{Organization, User, Visit} */
    private function graph(): array
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
            'ticket_number' => 'NDT-ST-2026-9001',
            'title' => 'Camera Installation',
            'priority' => 'normal',
            'source' => 'phone',
            'purpose' => 'installation_project',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'planned',
            'timezone' => 'America/Chicago',
        ]);

        return [$organization, $dispatcher, $visit];
    }

    /** @return array{User, OrganizationMembership} */
    private function member(Organization $organization, string $role, ?string $email = null): array
    {
        $user = User::factory()->create(['status' => 'active', ...($email !== null ? ['email' => $email] : [])]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }

    private function assign(User $actor, Visit $visit, array $membershipIds)
    {
        return $this->actingAs($actor)->put(route('office.visits.update', $visit), $this->assignmentPayload($membershipIds));
    }

    /** @param list<int> $membershipIds */
    private function assignmentPayload(array $membershipIds): array
    {
        return [
            'scheduled_start' => '2026-09-10T09:00',
            'scheduled_end' => '2026-09-10T10:00',
            'assignees' => $membershipIds,
        ];
    }

    private function subscription(Organization $organization, User $user, string $device): BrowserPushSubscription
    {
        $endpoint = "https://push.example.test/{$device}";

        return BrowserPushSubscription::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_sha256' => hash('sha256', $endpoint),
            'public_key' => "public-{$device}",
            'auth_token' => "auth-{$device}",
            'content_encoding' => 'aes128gcm',
            'last_registered_at' => now(),
        ]);
    }
}
