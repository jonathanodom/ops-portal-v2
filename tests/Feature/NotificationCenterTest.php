<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationAudience;
use App\Domain\Notifications\PortalNotificationPayload;
use App\Domain\Notifications\PortalNotificationPublisher;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationRecipient;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_recent_notifications_and_unread_count_are_user_and_organization_scoped(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, 'reviewer');
        [$otherUser] = $this->member($organization, 'reviewer');
        $otherOrganization = Organization::factory()->create();
        [$crossOrganizationUser] = $this->member($otherOrganization, 'reviewer');

        $older = $this->publish($organization, $user, 'Older notification', '2026-09-02 14:00:00');
        $newer = $this->publish($organization, $user, 'Newest notification', '2026-09-02 15:00:00');
        $older->recipients->first()->update(['read_at' => now()]);
        $this->publish($organization, $otherUser, 'Another user notification', '2026-09-02 16:00:00');
        $this->publish($otherOrganization, $crossOrganizationUser, 'Another organization notification', '2026-09-02 17:00:00');
        $this->publish($organization, $user, 'Email only', '2026-09-02 18:00:00', ['email']);

        $this->actingAs($user)->getJson(route('notifications.recent'))
            ->assertOk()
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.title', $newer->title)
            ->assertJsonPath('notifications.0.unread', true)
            ->assertJsonPath('notifications.1.title', $older->title)
            ->assertJsonMissing(['title' => 'Another user notification'])
            ->assertJsonMissing(['title' => 'Another organization notification'])
            ->assertJsonMissing(['title' => 'Email only']);

        $this->actingAs($user)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_history_is_paginated_newest_first_and_renders_empty_state(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, 'reviewer');
        foreach (range(1, 26) as $index) {
            $this->publish(
                $organization,
                $user,
                $index === 1 ? 'Oldest unique notification' : "Notification {$index}",
                CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC')->addMinutes($index)->toDateTimeString(),
            );
        }

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notification 26')
            ->assertSee('Notification 2')
            ->assertDontSee('Oldest unique notification')
            ->assertSee('page=2', false);

        [$emptyUser] = $this->member($organization, 'reviewer');
        $this->actingAs($emptyUser)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No notifications yet');
    }

    public function test_read_actions_are_idempotent_and_cannot_mutate_another_user_notification(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, 'reviewer');
        [$otherUser] = $this->member($organization, 'reviewer');
        $own = $this->publish($organization, $user, 'Own notification', '2026-09-02 15:00:00')->recipients->first();
        $second = $this->publish($organization, $user, 'Second notification', '2026-09-02 16:00:00')->recipients->first();
        $other = $this->publish($organization, $otherUser, 'Other notification', '2026-09-02 17:00:00')->recipients->first();

        $this->actingAs($user)->postJson(route('notifications.read', $own))->assertOk();
        $firstReadAt = $own->fresh()->read_at;
        $this->actingAs($user)->postJson(route('notifications.read', $own))->assertOk();
        $this->assertTrue($firstReadAt->equalTo($own->fresh()->read_at));
        $this->actingAs($user)->postJson(route('notifications.read', $other))->assertNotFound();

        $this->actingAs($user)->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('updated', 1);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_open_marks_read_and_uses_only_safe_internal_destinations(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, 'reviewer');
        $safe = $this->publish($organization, $user, 'Safe link', '2026-09-02 15:00:00', ['in_app'], '/office')->recipients->first();
        $unsafe = $this->legacyRecipient($organization, $user, 'https://malicious.example.test');

        $this->actingAs($user)->post(route('notifications.open', $safe))
            ->assertRedirect('/office');
        $this->assertNotNull($safe->fresh()->read_at);

        $this->actingAs($user)->post(route('notifications.open', $unsafe))
            ->assertRedirect(route('notifications.index'));
        $this->assertNotNull($unsafe->fresh()->read_at);
    }

    public function test_bell_renders_in_office_and_field_shells_with_accessible_controls(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, 'super_admin');

        foreach ([route('office.home'), route('field.home')] as $route) {
            $this->actingAs($user)->get($route)
                ->assertOk()
                ->assertSee('data-notification-center', false)
                ->assertSee('aria-label="Notifications"', false)
                ->assertSee('aria-controls="notification-center-panel"', false)
                ->assertSee('View All Notifications');
        }
    }

    public function test_notification_routes_require_authentication(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->getJson(route('notifications.recent'))->assertUnauthorized();
        $this->getJson(route('notifications.unread-count'))->assertUnauthorized();
        $this->postJson(route('notifications.read-all'))->assertUnauthorized();
        $this->postJson(route('notifications.read', 1))->assertUnauthorized();
    }

    private function publish(
        Organization $organization,
        User $user,
        string $title,
        string $occurredAt,
        array $channels = ['in_app'],
        string $actionUrl = '/office',
    ): PortalNotificationEvent {
        return app(PortalNotificationPublisher::class)->publish(
            $organization,
            new PortalNotificationPayload(
                eventKey: 'test.notification',
                category: 'Testing',
                title: $title,
                body: "Message for {$title}",
                actionUrl: $actionUrl,
                defaultChannels: $channels,
                occurredAt: CarbonImmutable::parse($occurredAt, 'UTC'),
            ),
            NotificationAudience::users([$user->id]),
        );
    }

    private function legacyRecipient(Organization $organization, User $user, string $actionUrl): PortalNotificationRecipient
    {
        $event = PortalNotificationEvent::query()->create([
            'organization_id' => $organization->id,
            'event_key' => 'test.legacy',
            'category' => 'Testing',
            'title' => 'Legacy unsafe link',
            'body' => 'Legacy record',
            'action_url' => $actionUrl,
            'priority' => 'normal',
            'metadata' => [],
            'audience' => ['type' => 'users', 'value' => [$user->id]],
            'default_channels' => ['in_app'],
            'required_channels' => [],
            'payload_sha256' => str_repeat('a', 64),
            'occurred_at' => now(),
        ]);

        return PortalNotificationRecipient::query()->create([
            'organization_id' => $organization->id,
            'portal_notification_event_id' => $event->id,
            'user_id' => $user->id,
            'channels' => ['in_app'],
        ]);
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
}
