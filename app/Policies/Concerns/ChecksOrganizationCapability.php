<?php

namespace App\Policies\Concerns;

use App\Models\OrganizationMembership;
use App\Models\User;

trait ChecksOrganizationCapability
{
    protected function hasCapability(User $user, int $organizationId, string $capability): bool
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('active', true))
            ->first();

        return $membership?->hasCapability($capability) ?? false;
    }
}
