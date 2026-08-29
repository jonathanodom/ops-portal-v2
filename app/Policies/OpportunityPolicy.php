<?php

namespace App\Policies;

use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

final class OpportunityPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'opportunities.view');
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->hasCapability($user, $opportunity->organization_id, 'opportunities.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'opportunities.manage');
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->hasCapability($user, $opportunity->organization_id, 'opportunities.manage');
    }

    public function administer(User $user, Opportunity|Organization $subject): bool
    {
        $organizationId = $subject instanceof Organization ? $subject->id : $subject->organization_id;

        return $this->hasCapability($user, $organizationId, 'opportunities.admin');
    }
}
