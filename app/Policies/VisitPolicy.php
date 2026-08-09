<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Visit;
use App\Policies\Concerns\ChecksOrganizationCapability;

class VisitPolicy
{
    use ChecksOrganizationCapability;

    public function dispatch(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'dispatch.manage');
    }

    public function view(User $user, Visit $visit): bool
    {
        $membership = $this->membership($user, $visit->organization_id);
        if (! $membership) {
            return false;
        }

        return $membership->hasCapability('visits.inspect_all')
            || $membership->hasCapability('service_tickets.view')
            || $visit->assignments()->where('organization_membership_id', $membership->id)->exists();
    }

    public function execute(User $user, Visit $visit): bool
    {
        $membership = $this->membership($user, $visit->organization_id);
        if (! $membership) {
            return false;
        }

        if ($membership->hasCapability('visits.execute_any')) {
            return true;
        }

        return $membership->hasCapability('visits.execute_assigned')
            && $visit->assignments()->where('organization_membership_id', $membership->id)->exists();
    }

    public function archive(User $user, Visit $visit): bool
    {
        return $this->hasCapability($user, $visit->organization_id, 'visits.archive.manage');
    }

    public function restore(User $user, Visit $visit): bool
    {
        return $this->archive($user, $visit);
    }

    public function forceDelete(User $user, Visit $visit): bool
    {
        return $this->archive($user, $visit);
    }

    private function membership(User $user, int $organizationId): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();
    }
}
