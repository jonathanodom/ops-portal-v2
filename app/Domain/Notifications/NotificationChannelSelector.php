<?php

namespace App\Domain\Notifications;

use App\Models\Organization;
use App\Models\PortalNotificationPreference;
use App\Models\User;

final class NotificationChannelSelector
{
    /** @return list<string> */
    public function select(Organization $organization, User $user, PortalNotificationPayload $payload): array
    {
        $enabled = array_fill_keys(PortalNotificationPayload::CHANNELS, false);
        foreach ($payload->defaultChannels as $channel) {
            $enabled[$channel] = true;
        }

        $preferences = PortalNotificationPreference::query()
            ->forOrganization($organization->id)
            ->where('user_id', $user->id)
            ->whereIn('event_key', ['*', $payload->eventKey])
            ->get()
            ->keyBy('event_key');

        foreach (['*', $payload->eventKey] as $eventKey) {
            $preference = $preferences->get($eventKey);
            if (! $preference) {
                continue;
            }
            foreach (PortalNotificationPayload::CHANNELS as $channel) {
                $value = $preference->getAttribute($channel.'_enabled');
                if ($value !== null) {
                    $enabled[$channel] = (bool) $value;
                }
            }
        }

        foreach ($payload->requiredChannels as $channel) {
            $enabled[$channel] = true;
        }

        return collect(PortalNotificationPayload::CHANNELS)
            ->filter(fn (string $channel): bool => $enabled[$channel])
            ->values()
            ->all();
    }
}
