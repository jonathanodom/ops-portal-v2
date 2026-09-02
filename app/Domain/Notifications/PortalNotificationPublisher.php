<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\NotificationRecipientResolver;
use App\Models\Organization;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class PortalNotificationPublisher
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationChannelSelector $channels,
    ) {}

    public function publish(
        Organization $organization,
        PortalNotificationPayload $payload,
        NotificationAudience $audience,
    ): PortalNotificationEvent {
        return DB::transaction(function () use ($organization, $payload, $audience): PortalNotificationEvent {
            $this->assertActorScope($organization, $payload);
            $this->assertRelatedScope($organization, $payload);

            $normalized = $payload->normalized();
            $normalizedAudience = $audience->normalized();
            $payloadHash = hash('sha256', json_encode(
                ['payload' => $normalized, 'audience' => $normalizedAudience],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $deduplicationHash = $payload->idempotencyKey === null
                ? null
                : hash('sha256', $payload->eventKey."\0".$payload->idempotencyKey);

            if ($deduplicationHash !== null) {
                $existing = PortalNotificationEvent::query()
                    ->forOrganization($organization->id)
                    ->where('deduplication_hash', $deduplicationHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_sha256, $payloadHash)) {
                        throw new LogicException('Notification idempotency key was reused with a different payload.');
                    }

                    return $existing->load('recipients.user');
                }
            }

            $event = PortalNotificationEvent::query()->create([
                ...$normalized,
                'organization_id' => $organization->id,
                'audience' => $normalizedAudience,
                'payload_sha256' => $payloadHash,
                'deduplication_hash' => $deduplicationHash,
            ]);

            foreach ($this->recipients->resolve($organization, $audience) as $user) {
                PortalNotificationRecipient::query()->create([
                    'organization_id' => $organization->id,
                    'portal_notification_event_id' => $event->id,
                    'user_id' => $user->id,
                    'channels' => $this->channels->select($organization, $user, $payload),
                ]);
            }

            return $event->load('recipients.user');
        });
    }

    private function assertActorScope(Organization $organization, PortalNotificationPayload $payload): void
    {
        if ($payload->actorId === null) {
            return;
        }

        $isMember = $organization->memberships()
            ->where('user_id', $payload->actorId)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->exists();
        if (! $isMember) {
            throw new InvalidArgumentException('Notification actor must be an active organization member.');
        }
    }

    private function assertRelatedScope(Organization $organization, PortalNotificationPayload $payload): void
    {
        if ($payload->relatedType === null) {
            return;
        }

        $modelClass = Relation::getMorphedModel($payload->relatedType) ?? $payload->relatedType;
        if (! is_a($modelClass, Model::class, true)) {
            throw new InvalidArgumentException('Notification related type must be an application model.');
        }

        $exists = $modelClass::query()
            ->whereKey($payload->relatedId)
            ->where('organization_id', $organization->id)
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException('Notification related record must belong to the organization.');
        }
    }
}
