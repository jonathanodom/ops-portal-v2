<?php

namespace App\Domain\Notifications;

use App\Models\OfficeUpdate;
use App\Models\PortalNotificationEvent;
use Illuminate\Support\Str;

final class OfficeUpdateNotifier
{
    public function __construct(private readonly PortalNotificationManager $notifications) {}

    /** @param list<int> $userIds */
    public function notify(OfficeUpdate $update, array $userIds): PortalNotificationEvent
    {
        $update->loadMissing('organization');

        return $this->notifications->publish(
            $update->organization,
            new PortalNotificationPayload(
                eventKey: 'office_update.published',
                category: 'Office',
                title: Str::limit('Office Update — '.$update->title, 180, ''),
                body: $update->body,
                actionUrl: route('office-updates.show', $update, false),
                relatedType: $update->getMorphClass(),
                relatedId: $update->id,
                actorId: $update->published_by_id,
                metadata: [
                    'action_label' => 'View Update',
                    'office_update_id' => $update->id,
                    'audience_type' => $update->audience_type,
                    'recipient_count' => $update->recipient_count,
                    'push_title' => 'Office Update',
                    'push_body' => Str::limit($update->title.' — Open Ops Portal to review.', 220),
                ],
                defaultChannels: ['in_app', 'email', 'push'],
                requiredChannels: ['in_app'],
                idempotencyKey: "office_update.published:{$update->id}",
                occurredAt: $update->published_at,
            ),
            NotificationAudience::users($userIds),
        );
    }
}
