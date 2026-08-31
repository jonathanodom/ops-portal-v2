<?php

namespace App\Policies;

use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

final class CommercialLeadIntakePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'opportunities.view');
    }

    public function view(User $user, CommercialLeadIntake $lead): bool
    {
        return $this->hasCapability($user, $lead->organization_id, 'opportunities.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'opportunities.manage');
    }

    public function convert(User $user, CommercialLeadIntake $lead): bool
    {
        return $this->hasCapability($user, $lead->organization_id, 'opportunities.manage');
    }

    public function manage(User $user, CommercialLeadIntake $lead): bool
    {
        return $this->hasCapability($user, $lead->organization_id, 'opportunities.manage');
    }
}
