<?php

namespace App\Domain\Notifications;

use App\Models\Organization;
use App\Models\PortalNotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

final class NotificationChannelSelector
{
    /** @return list<string> */
    public function select(Organization $organization, User $user, PortalNotificationPayload $payload): array
    {
        return $this->selectMany($organization, collect([$user]), $payload)[$user->id];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, list<string>>
     */
    public function selectMany(Organization $organization, Collection $users, PortalNotificationPayload $payload): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $preferences = PortalNotificationPreference::query()
            ->forOrganization($organization->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->whereIn('event_key', ['*', $payload->eventKey])
            ->get()
            ->groupBy('user_id');

        return $users->mapWithKeys(fn (User $user): array => [
            $user->id => $this->resolve($preferences->get($user->id, collect())->keyBy('event_key'), $payload),
        ])->all();
    }

    /** @return list<string> */
    private function resolve(Collection $preferences, PortalNotificationPayload $payload): array
    {
        $enabled = array_fill_keys(PortalNotificationPayload::CHANNELS, false);
        foreach ($payload->defaultChannels as $channel) {
            $enabled[$channel] = true;
        }

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
