<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationAudience;
use App\Domain\Notifications\NotificationPreferenceCatalog;
use App\Domain\Notifications\PortalNotificationPayload;
use App\Domain\Notifications\PortalNotificationPublisher;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationPreference;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_missing_preferences_render_safe_enabled_defaults(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->member($organization);

        $this->actingAs($user)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('Notification preferences')
            ->assertSee('New Leads')
            ->assertSee('Job Assignments')
            ->assertSee('Schedule Changes')
            ->assertSee('Return Visit Updates')
            ->assertSee('Office Updates')
            ->assertSee('Always on')
            ->assertSee('name="preferences[new_leads][email]" value="1"', false)
            ->assertSee('name="preferences[new_leads][push]" value="1"', false)
            ->assertSee('checked', false);
    }

    public function test_user_can_update_only_their_own_canonical_preferences(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->member($organization);
        $other = $this->member($organization);

        $payload = [];
        foreach (app(NotificationPreferenceCatalog::class)->categories() as $key => $category) {
            $payload[$key] = ['email' => $key !== 'new_leads', 'push' => $key !== 'schedule_changes'];
        }

        $this->actingAs($user)->put(route('notifications.preferences.update'), [
            'user_id' => $other->id,
            'preferences' => $payload,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('portal_notification_preferences', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event_key' => 'lead.submitted',
            'in_app_enabled' => true,
            'email_enabled' => false,
            'push_enabled' => true,
        ]);
        foreach (['ticket.scheduled', 'ticket.rescheduled', 'ticket.unscheduled'] as $eventKey) {
            $this->assertDatabaseHas('portal_notification_preferences', [
                'user_id' => $user->id,
                'event_key' => $eventKey,
                'push_enabled' => false,
            ]);
        }
        $this->assertDatabaseMissing('portal_notification_preferences', ['user_id' => $other->id]);
        $this->assertDatabaseCount('portal_notification_preferences', 7);
    }

    public function test_channel_preferences_apply_across_events_without_disabling_in_app(): void
    {
        $organization = Organization::factory()->create();
        $user = $this->member($organization);
        PortalNotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event_key' => 'ticket.assigned',
            'in_app_enabled' => false,
            'email_enabled' => false,
            'push_enabled' => true,
        ]);

        $event = app(PortalNotificationPublisher::class)->publish(
            $organization,
            $this->payload('ticket.assigned'),
            NotificationAudience::users([$user->id]),
        );

        $this->assertSame(['in_app', 'push'], $event->recipients->sole()->channels);

        PortalNotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'event_key' => 'ticket.scheduled',
            'in_app_enabled' => false,
            'email_enabled' => true,
            'push_enabled' => false,
        ]);
        $scheduled = app(PortalNotificationPublisher::class)->publish(
            $organization,
            $this->payload('ticket.scheduled'),
            NotificationAudience::users([$user->id]),
        );

        $this->assertSame(['in_app', 'email'], $scheduled->recipients->sole()->channels);
    }

    public function test_publisher_loads_preferences_once_for_a_multi_recipient_audience(): void
    {
        $organization = Organization::factory()->create();
        $users = collect([$this->member($organization), $this->member($organization), $this->member($organization)]);
        $preferenceQueries = 0;
        DB::listen(function ($query) use (&$preferenceQueries): void {
            if (str_contains($query->sql, 'portal_notification_preferences')) {
                $preferenceQueries++;
            }
        });

        app(PortalNotificationPublisher::class)->publish(
            $organization,
            $this->payload('office_update.published'),
            NotificationAudience::users($users->pluck('id')->all()),
        );

        $this->assertSame(1, $preferenceQueries);
    }

    public function test_preferences_are_scoped_to_the_active_organization_membership(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        $user = $this->member($firstOrganization);
        OrganizationMembership::query()->create([
            'organization_id' => $secondOrganization->id,
            'user_id' => $user->id,
            'status' => 'inactive',
        ]);
        PortalNotificationPreference::query()->create([
            'organization_id' => $secondOrganization->id,
            'user_id' => $user->id,
            'event_key' => 'lead.submitted',
            'email_enabled' => false,
            'push_enabled' => false,
        ]);

        $this->actingAs($user)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('name="preferences[new_leads][email]" value="1"', false)
            ->assertSee('checked', false);
    }

    private function payload(string $eventKey): PortalNotificationPayload
    {
        return new PortalNotificationPayload(
            eventKey: $eventKey,
            category: 'Operations',
            title: 'Operational update',
            body: 'An operational record changed.',
            defaultChannels: ['in_app', 'email', 'push'],
            requiredChannels: ['in_app'],
            idempotencyKey: $eventKey.'-preference-test',
        );
    }

    private function member(Organization $organization): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'dispatcher')->sole());

        return $user;
    }
}
