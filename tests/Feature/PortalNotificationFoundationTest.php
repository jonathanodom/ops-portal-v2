<?php

namespace Tests\Feature;

use App\Domain\Notifications\NotificationAudience;
use App\Domain\Notifications\PortalNotificationPayload;
use App\Domain\Notifications\PortalNotificationPublisher;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationPreference;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class PortalNotificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_payload_is_normalized_and_hashes_metadata_deterministically(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-09-02 15:30:00', 'UTC');
        $first = $this->payload(metadata: ['record_id' => 7, 'state' => ['to' => 'open', 'from' => 'new']], occurredAt: $occurredAt);
        $second = $this->payload(metadata: ['state' => ['from' => 'new', 'to' => 'open'], 'record_id' => 7], occurredAt: $occurredAt);

        $this->assertSame($first->sha256(), $second->sha256());
        $this->assertSame(['record_id', 'state'], array_keys($first->normalized()['metadata']));
    }

    public function test_payload_rejects_invalid_contract_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload(defaultChannels: ['sms']);
    }

    public function test_payload_rejects_external_action_urls(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PortalNotificationPayload(
            eventKey: 'leads.received',
            category: 'Leads',
            title: 'New lead',
            body: 'Open the lead.',
            actionUrl: 'https://malicious.example.test',
        );
    }

    public function test_publisher_persists_scoped_event_recipients_and_channel_preferences(): void
    {
        $organization = Organization::factory()->create();
        [$actor] = $this->member($organization, 'super_admin');
        [$recipient] = $this->member($organization, 'dispatcher');
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);

        PortalNotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $recipient->id,
            'event_key' => '*',
            'email_enabled' => false,
        ]);
        PortalNotificationPreference::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $recipient->id,
            'event_key' => 'leads.received',
            'in_app_enabled' => false,
            'push_enabled' => true,
        ]);

        $event = app(PortalNotificationPublisher::class)->publish(
            $organization,
            $this->payload(
                actorId: $actor->id,
                relatedType: $customer->getMorphClass(),
                relatedId: $customer->id,
                defaultChannels: ['in_app', 'email', 'push'],
                requiredChannels: ['in_app'],
            ),
            NotificationAudience::users([$recipient->id]),
        );

        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame($customer->id, $event->related_id);
        $this->assertSame(['type' => 'users', 'value' => [$recipient->id]], $event->audience);
        $this->assertSame(64, strlen($event->payload_sha256));
        $this->assertCount(1, $event->recipients);
        $this->assertSame($recipient->id, $event->recipients->first()->user_id);
        $this->assertSame(['in_app', 'push'], $event->recipients->first()->channels);
        $this->assertNull($event->recipients->first()->read_at);
    }

    public function test_audiences_exclude_inactive_and_cross_organization_members_and_honor_overrides(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        [$allowed] = $this->member($organization, 'dispatcher');
        [$denied, $deniedMembership] = $this->member($organization, 'super_admin');
        [$inactive, $inactiveMembership] = $this->member($organization, 'dispatcher');
        [$other] = $this->member($otherOrganization, 'dispatcher');

        $capability = Capability::query()->where('key', 'opportunities.view')->sole();
        $deniedMembership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $inactiveMembership->update(['status' => 'inactive']);

        $publisher = app(PortalNotificationPublisher::class);
        $event = $publisher->publish(
            $organization,
            $this->payload(idempotencyKey: 'capability-audience-1'),
            NotificationAudience::capability('opportunities.view'),
        );

        $this->assertSame([$allowed->id], $event->recipients->pluck('user_id')->all());

        $explicit = $publisher->publish(
            $organization,
            $this->payload(idempotencyKey: 'explicit-audience-1'),
            NotificationAudience::users([$allowed->id, $denied->id, $inactive->id, $other->id]),
        );
        $this->assertEqualsCanonicalizing([$allowed->id, $denied->id], $explicit->recipients->pluck('user_id')->all());

        $role = $publisher->publish(
            $organization,
            $this->payload(idempotencyKey: 'role-audience-1'),
            NotificationAudience::role('dispatcher'),
        );
        $this->assertSame([$allowed->id], $role->recipients->pluck('user_id')->all());
    }

    public function test_idempotent_publish_replays_same_payload_and_rejects_changed_payload(): void
    {
        $organization = Organization::factory()->create();
        [$recipient] = $this->member($organization, 'dispatcher');
        $publisher = app(PortalNotificationPublisher::class);
        $payload = $this->payload(idempotencyKey: 'lead-received-100');

        $first = $publisher->publish($organization, $payload, NotificationAudience::users([$recipient->id]));
        $replay = $publisher->publish($organization, $payload, NotificationAudience::users([$recipient->id]));

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, PortalNotificationEvent::query()->count());
        $this->assertCount(1, $replay->recipients);

        try {
            $publisher->publish(
                $organization,
                $payload,
                NotificationAudience::users([]),
            );
            $this->fail('A changed audience must not reuse an idempotency key.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('different payload', $exception->getMessage());
        }

        try {
            $publisher->publish(
                $organization,
                $this->payload(title: 'Changed title', idempotencyKey: 'lead-received-100'),
                NotificationAudience::users([$recipient->id]),
            );
            $this->fail('A changed payload must not reuse an idempotency key.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('different payload', $exception->getMessage());
        }

        $this->assertSame(1, PortalNotificationEvent::query()->count());
    }

    public function test_publisher_rejects_cross_organization_actor_and_related_records(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        [$otherActor] = $this->member($otherOrganization, 'super_admin');
        $otherCustomer = Customer::factory()->create(['organization_id' => $otherOrganization->id]);
        $publisher = app(PortalNotificationPublisher::class);

        try {
            $publisher->publish(
                $organization,
                $this->payload(actorId: $otherActor->id),
                NotificationAudience::users([]),
            );
            $this->fail('A cross-organization actor must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('active organization member', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $publisher->publish(
            $organization,
            $this->payload(relatedType: $otherCustomer->getMorphClass(), relatedId: $otherCustomer->id),
            NotificationAudience::users([]),
        );
    }

    private function payload(
        string $title = 'New lead received',
        array $metadata = ['lead_id' => 100],
        array $defaultChannels = ['in_app'],
        array $requiredChannels = [],
        ?string $idempotencyKey = null,
        ?int $actorId = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?CarbonImmutable $occurredAt = null,
    ): PortalNotificationPayload {
        return new PortalNotificationPayload(
            eventKey: 'leads.received',
            category: 'Leads',
            title: $title,
            body: 'A new lead is ready for office follow-up.',
            actionUrl: '/office/leads/100',
            relatedType: $relatedType,
            relatedId: $relatedId,
            actorId: $actorId,
            metadata: $metadata,
            defaultChannels: $defaultChannels,
            requiredChannels: $requiredChannels,
            idempotencyKey: $idempotencyKey,
            occurredAt: $occurredAt ?? CarbonImmutable::parse('2026-09-02 15:30:00', 'UTC'),
        );
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
