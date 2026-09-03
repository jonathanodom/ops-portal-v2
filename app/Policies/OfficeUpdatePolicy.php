<?php

namespace App\Policies;

use App\Models\OfficeUpdate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

final class OfficeUpdatePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    public function view(User $user, OfficeUpdate $update): bool
    {
        if ($this->hasCapability($user, $update->organization_id, 'users.manage')) {
            return true;
        }

        return $update->recipients()
            ->where('organization_id', $update->organization_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function publish(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'experience.office.access')
            && $this->hasCapability($user, $organization->id, 'users.manage');
    }
}
