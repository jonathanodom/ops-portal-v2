<?php

namespace Tests\Feature;

use App\Jobs\DeliverPortalNotificationEmail;
use App\Jobs\DeliverPortalNotificationPush;
use App\Models\BrowserPushSubscription;
use App\Models\OfficeUpdate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Queue::fake();
    }

    public function test_authorized_manager_publishes_to_all_active_human_staff_through_all_channels(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$manager] = $this->member($organization, 'super_admin');
        [$technician] = $this->member($organization, 'technician');
        [$inactive] = $this->member($organization, 'technician', status: 'inactive');
        [$service] = $this->member($organization, 'jarvis_service');
        $this->subscription($organization, $technician);

        $this->actingAs($manager)->get(route('office-updates.create'))
            ->assertOk()
            ->assertSee('New Office Update')
            ->assertSee('All Staff')
            ->assertSee('Selected Staff');
        $response = $this->actingAs($manager)->post(route('office-updates.store'), $this->payload());

        $update = OfficeUpdate::query()->with('recipients')->sole();
        $response->assertRedirect(route('office-updates.show', $update));
        $this->assertSame('all_staff', $update->audience_type);
        $this->assertSame(2, $update->recipient_count);
        $this->assertEqualsCanonicalizing([$manager->id, $technician->id], $update->recipients->pluck('user_id')->all());
        $this->assertNotContains($inactive->id, $update->audience_snapshot['resolved_user_ids']);
        $this->assertNotContains($service->id, $update->audience_snapshot['resolved_user_ids']);

        $event = PortalNotificationEvent::query()->with('recipients')->sole();
        $this->assertSame('office_update.published', $event->event_key);
        $this->assertSame('Office Update — Labor Day Hours', $event->title);
        $this->assertSame(route('office-updates.show', $update, false), $event->action_url);
        $this->assertEqualsCanonicalizing([$manager->id, $technician->id], $event->recipients->pluck('user_id')->all());
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 2);
        Queue::assertPushed(DeliverPortalNotificationPush::class, 1);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'office_update.published',
            'subject_id' => $update->id,
            'actor_id' => $manager->id,
        ]);
    }

    public function test_selected_staff_receive_independent_notifications_and_unrelated_staff_cannot_view(): void
    {
        $organization = Organization::factory()->create();
        [$manager] = $this->member($organization, 'super_admin');
        [$first] = $this->member($organization, 'technician');
        [$second] = $this->member($organization, 'technician');
        [$unrelated] = $this->member($organization, 'technician');

        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'audience_type' => 'selected_staff',
            'recipient_user_ids' => [$first->id, $second->id],
        ]))->assertRedirect();

        $update = OfficeUpdate::query()->with('recipients')->sole();
        $this->assertSame(2, $update->recipient_count);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $update->recipients->pluck('user_id')->all());
        $this->assertDatabaseCount('portal_notification_recipients', 2);
        $this->actingAs($first)->get(route('office-updates.show', $update))->assertOk()->assertSee('Labor Day Hours');
        $this->actingAs($unrelated)->get(route('office-updates.show', $update))->assertForbidden();
        $this->actingAs($unrelated)->get(route('office-updates.index'))->assertOk()->assertDontSee('Labor Day Hours');
        $this->actingAs($manager)->get(route('office-updates.show', $update))->assertOk();

        $notification = $update->organization->portalNotificationEvents()->sole()->recipients()->where('user_id', $first->id)->sole();
        $this->actingAs($first)->post(route('notifications.read', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs($first)->get(route('office-updates.show', $update))->assertOk();
    }

    public function test_invalid_or_unauthorized_audiences_create_nothing(): void
    {
        $organization = Organization::factory()->create();
        [$manager] = $this->member($organization, 'super_admin');
        [$technician] = $this->member($organization, 'technician');
        [$foreign] = $this->member(Organization::factory()->create(), 'technician');

        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'audience_type' => 'selected_staff',
            'recipient_user_ids' => [],
        ]))->assertSessionHasErrors('recipient_user_ids');
        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'audience_type' => 'selected_staff',
            'recipient_user_ids' => [$foreign->id],
        ]))->assertSessionHasErrors('recipient_user_ids');
        $this->actingAs($technician)->post(route('office-updates.store'), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('office_updates', 0);
        $this->assertDatabaseCount('portal_notification_events', 0);
    }

    public function test_duplicate_publish_token_replays_one_update_and_one_delivery_set(): void
    {
        $organization = Organization::factory()->create();
        [$manager] = $this->member($organization, 'super_admin');
        [, $membership] = $this->member($organization, 'technician');
        $payload = $this->payload([
            'audience_type' => 'selected_staff',
            'recipient_user_ids' => [$membership->user_id],
            'publish_token' => (string) Str::uuid(),
        ]);

        $this->actingAs($manager)->post(route('office-updates.store'), $payload)->assertRedirect();
        $this->actingAs($manager)->post(route('office-updates.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('office_updates', 1);
        $this->assertDatabaseCount('office_update_recipients', 1);
        $this->assertDatabaseCount('portal_notification_events', 1);
        $this->assertDatabaseCount('portal_notification_recipients', 1);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 1);

        $changed = [...$payload, 'body' => 'Different content'];
        $this->actingAs($manager)->post(route('office-updates.store'), $changed)->assertSessionHasErrors('publish_token');
        $this->assertDatabaseCount('office_updates', 1);
    }

    public function test_missing_email_and_notification_failure_do_not_remove_the_published_update(): void
    {
        $organization = Organization::factory()->create();
        [$manager] = $this->member($organization, 'super_admin');
        [$recipient] = $this->member($organization, 'technician', email: '');

        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'audience_type' => 'selected_staff',
            'recipient_user_ids' => [$recipient->id],
        ]))->assertRedirect();
        $this->assertDatabaseCount('office_updates', 1);
        $this->assertDatabaseCount('portal_notification_recipients', 1);
        Queue::assertNotPushed(DeliverPortalNotificationEmail::class);

        Schema::drop('portal_notification_events');
        Log::spy();
        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'publish_token' => (string) Str::uuid(),
        ]))->assertRedirect();
        $this->assertDatabaseCount('office_updates', 2);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_update_content_is_plain_text_and_routes_are_organization_scoped(): void
    {
        $organization = Organization::factory()->create();
        [$manager] = $this->member($organization, 'super_admin');
        $this->actingAs($manager)->post(route('office-updates.store'), $this->payload([
            'body' => '<script>alert("no")</script> Staff message',
        ]));
        $update = OfficeUpdate::query()->sole();

        $this->actingAs($manager)->get(route('office-updates.show', $update))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;no&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert("no")</script>', false);

        [$foreignManager] = $this->member(Organization::factory()->create(), 'super_admin');
        $this->actingAs($foreignManager)->get(route('office-updates.show', $update))->assertNotFound();
    }

    /** @return array{User, OrganizationMembership} */
    private function member(Organization $organization, string $role, string $status = 'active', ?string $email = null): array
    {
        $user = User::factory()->create(['status' => 'active', ...($email !== null ? ['email' => $email] : [])]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }

    private function payload(array $overrides = []): array
    {
        return [...[
            'title' => 'Labor Day Hours',
            'body' => 'The office will open at 10:00 AM Monday.',
            'audience_type' => 'all_staff',
            'recipient_user_ids' => [],
            'publish_token' => (string) Str::uuid(),
        ], ...$overrides];
    }

    private function subscription(Organization $organization, User $user): BrowserPushSubscription
    {
        $endpoint = 'https://push.example.test/office-update';

        return BrowserPushSubscription::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_sha256' => hash('sha256', $endpoint),
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
            'last_registered_at' => now(),
        ]);
    }
}
