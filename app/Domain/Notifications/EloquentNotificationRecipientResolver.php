<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\NotificationRecipientResolver;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class EloquentNotificationRecipientResolver implements NotificationRecipientResolver
{
    public function resolve(Organization $organization, NotificationAudience $audience): Collection
    {
        $query = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->with(['user', 'roles.capabilities', 'capabilityOverrides']);

        if ($audience->type === 'users') {
            $query->whereIn('user_id', $audience->value);
        } elseif ($audience->type === 'role') {
            $query->whereHas('roles', fn ($query) => $query->where('key', $audience->value));
        } elseif ($audience->type !== 'capability') {
            throw new InvalidArgumentException('Unsupported notification audience type.');
        }

        $memberships = $query->orderBy('id')->get();
        if ($audience->type === 'capability') {
            $memberships = $memberships->filter(fn (OrganizationMembership $membership): bool => $membership->hasCapability($audience->value));
        }

        return $memberships->map->user->unique('id')->values();
    }
}
