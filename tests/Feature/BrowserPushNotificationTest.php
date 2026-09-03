<?php

namespace Tests\Feature;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Domain\Notifications\Contracts\BrowserPushTransport;
use App\Domain\Notifications\NewLeadSubmittedNotifier;
use App\Domain\Notifications\PushDeliveryResult;
use App\Jobs\DeliverPortalNotificationEmail;
use App\Jobs\DeliverPortalNotificationPush;
use App\Models\BrowserPushSubscription;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationPushDelivery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BrowserPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config([
            'services.web_push.vapid_subject' => 'mailto:ops@example.test',
            'services.web_push.vapid_public_key' => 'public-vapid-value',
            'services.web_push.vapid_private_key' => 'private-vapid-secret',
        ]);
    }

    public function test_authenticated_user_can_register_refresh_and_remove_own_subscription(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization);
        $payload = $this->subscriptionPayload('device-one');

        $configuration = $this->actingAs($user)->getJson(route('notifications.push.configuration'))
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('public_key', 'public-vapid-value')
            ->assertJsonMissing(['private_key' => 'private-vapid-secret']);
        $configuration->assertDontSee('private-vapid-secret');
        $this->actingAs($user)->postJson(route('notifications.push.subscriptions.store'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'subscribed');
        $this->actingAs($user)->postJson(route('notifications.push.subscriptions.store'), [
            ...$payload,
            'keys' => ['p256dh' => 'refreshed-key', 'auth' => 'refreshed-auth'],
        ])->assertOk();

        $this->assertDatabaseCount('browser_push_subscriptions', 1);
        $this->assertSame('refreshed-key', BrowserPushSubscription::query()->sole()->public_key);

        $this->actingAs($user)->deleteJson(route('notifications.push.subscriptions.destroy'), [
            'endpoint' => $payload['endpoint'],
        ])->assertNoContent();
        $this->actingAs($user)->deleteJson(route('notifications.push.subscriptions.destroy'), [
            'endpoint' => $payload['endpoint'],
        ])->assertNoContent();
        $this->assertDatabaseCount('browser_push_subscriptions', 0);
    }

    public function test_one_user_can_register_multiple_devices_and_cannot_manipulate_another_user_subscription(): void
    {
        $organization = Organization::factory()->create();
        [$owner] = $this->member($organization);
        [$other] = $this->member($organization);
        $first = $this->subscriptionPayload('device-one');
        $second = $this->subscriptionPayload('device-two');

        $this->actingAs($owner)->postJson(route('notifications.push.subscriptions.store'), $first)->assertOk();
        $this->actingAs($owner)->postJson(route('notifications.push.subscriptions.store'), $second)->assertOk();
        $this->assertDatabaseCount('browser_push_subscriptions', 2);

        $this->actingAs($other)->postJson(route('notifications.push.subscriptions.store'), $first)->assertConflict();
        $this->actingAs($other)->deleteJson(route('notifications.push.subscriptions.destroy'), [
            'endpoint' => $first['endpoint'],
        ])->assertNoContent();
        $this->assertDatabaseCount('browser_push_subscriptions', 2);
        $this->assertSame([$owner->id], BrowserPushSubscription::query()->distinct()->pluck('user_id')->all());
    }

    public function test_new_lead_queues_push_per_device_without_duplicating_the_in_app_recipient(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization);
        $this->storeSubscription($organization, $user, 'device-one');
        $this->storeSubscription($organization, $user, 'device-two');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->leadPayload(),
            'source' => 'website',
            'status' => 'received',
        ]);

        $first = app(NewLeadSubmittedNotifier::class)->notify($lead);
        $replay = app(NewLeadSubmittedNotifier::class)->notify($lead);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('portal_notification_events', 1);
        $this->assertDatabaseCount('portal_notification_recipients', 1);
        $this->assertDatabaseCount('portal_notification_push_deliveries', 2);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 1);
        Queue::assertPushed(DeliverPortalNotificationPush::class, 2);
    }

    public function test_missing_subscription_skips_push_without_affecting_in_app_or_email(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $this->member($organization);
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->leadPayload(),
            'source' => 'website',
            'status' => 'received',
        ]);

        app(NewLeadSubmittedNotifier::class)->notify($lead);

        $this->assertDatabaseCount('portal_notification_recipients', 1);
        $this->assertDatabaseCount('portal_notification_push_deliveries', 0);
        Queue::assertPushed(DeliverPortalNotificationEmail::class, 1);
        Queue::assertNotPushed(DeliverPortalNotificationPush::class);
    }

    public function test_push_job_uses_private_normalized_content_and_cleans_up_expired_subscription(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization);
        $this->storeSubscription($organization, $user, 'device-one');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->leadPayload(),
            'source' => 'website',
            'status' => 'received',
        ]);
        app(NewLeadSubmittedNotifier::class)->notify($lead);
        $delivery = PortalNotificationPushDelivery::query()->sole();
        $transport = new class implements BrowserPushTransport
        {
            public array $payload = [];

            public PushDeliveryResult $result;

            public function __construct()
            {
                $this->result = new PushDeliveryResult(true);
            }

            public function send(BrowserPushSubscription $subscription, array $payload): PushDeliveryResult
            {
                $this->payload = $payload;

                return $this->result;
            }
        };

        $job = new DeliverPortalNotificationPush($delivery->id);
        $job->handle($transport);
        $this->assertSame('New Lead Submitted', $transport->payload['title']);
        $this->assertSame('A new lead has been received in Ops Portal.', $transport->payload['body']);
        $this->assertSame('/office/leads/'.$lead->id, $transport->payload['url']);
        $this->assertStringNotContainsString($lead->email, json_encode($transport->payload));
        $this->assertSame('sent', $delivery->fresh()->status);

        $second = $this->storeSubscription($organization, $user, 'device-two');
        $recipient = PortalNotificationEvent::query()->sole()->recipients()->sole();
        $expired = PortalNotificationPushDelivery::query()->create([
            'organization_id' => $organization->id,
            'portal_notification_recipient_id' => $recipient->id,
            'browser_push_subscription_id' => $second->id,
            'status' => 'queued',
            'queued_at' => now(),
        ]);
        $transport->result = new PushDeliveryResult(false, true, 'http_410');
        (new DeliverPortalNotificationPush($expired->id))->handle($transport);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertNotNull($second->fresh()->disabled_at);
    }

    public function test_terminal_push_failure_is_observable_without_corrupting_lead_or_notification(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization);
        $this->storeSubscription($organization, $user, 'device-one');
        $lead = app(LeadIntakeCreator::class)->create($organization, [
            ...$this->leadPayload(),
            'source' => 'website',
            'status' => 'received',
        ]);
        app(NewLeadSubmittedNotifier::class)->notify($lead);
        $delivery = PortalNotificationPushDelivery::query()->sole();
        Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Portal browser push delivery failed.'
            && $context['delivery_id'] === $delivery->id
            && $context['failure_type'] === 'RuntimeException');

        (new DeliverPortalNotificationPush($delivery->id))->failed(new RuntimeException('provider secret'));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertTrue($lead->fresh()->exists);
        $this->assertDatabaseCount('portal_notification_events', 1);
        $this->assertDatabaseCount('portal_notification_recipients', 1);
    }

    public function test_notification_center_renders_explicit_push_controls_and_service_worker_is_safe(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization);

        $this->actingAs($user)->get(route('office.home'))
            ->assertOk()
            ->assertSee('Enable browser notifications')
            ->assertSee('data-browser-push', false);

        $worker = file_get_contents(public_path('ops-notifications-sw.js'));
        $this->assertStringContainsString("addEventListener('push'", $worker);
        $this->assertStringContainsString("addEventListener('notificationclick'", $worker);
        $this->assertStringContainsString('candidate.origin === self.location.origin', $worker);
    }

    /** @return array{User, OrganizationMembership} */
    private function member(Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'dispatcher')->sole());

        return [$user, $membership];
    }

    private function storeSubscription(Organization $organization, User $user, string $device): BrowserPushSubscription
    {
        $payload = $this->subscriptionPayload($device);

        return BrowserPushSubscription::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'endpoint' => $payload['endpoint'],
            'endpoint_sha256' => hash('sha256', $payload['endpoint']),
            'public_key' => $payload['keys']['p256dh'],
            'auth_token' => $payload['keys']['auth'],
            'content_encoding' => 'aes128gcm',
            'last_registered_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(string $device): array
    {
        return [
            'endpoint' => "https://push.example.test/{$device}",
            'keys' => ['p256dh' => "public-{$device}", 'auth' => "auth-{$device}"],
            'contentEncoding' => 'aes128gcm',
            'user_id' => 999999,
        ];
    }

    /** @return array<string, mixed> */
    private function leadPayload(): array
    {
        return [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => '940-555-1212',
            'email' => 'jane@example.test',
            'customer_type' => 'Residential',
            'zip' => '76450',
            'service_interest' => 'Camera Installation',
            'preferred_contact' => 'Phone',
            'timeline' => 'Within 30 days',
            'details' => 'Need a site review.',
        ];
    }
}
