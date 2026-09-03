<?php

namespace App\Domain\Notifications;

use App\Models\Organization;
use App\Models\PortalNotificationEvent;

final class PortalNotificationManager
{
    public function __construct(
        private readonly PortalNotificationPublisher $publisher,
        private readonly StaffNotificationEmailChannel $email,
    ) {}

    public function publish(
        Organization $organization,
        PortalNotificationPayload $payload,
        NotificationAudience $audience,
    ): PortalNotificationEvent {
        $event = $this->publisher->publish($organization, $payload, $audience);
        $this->email->queue($event);

        return $event;
    }
}
