<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class UnitOfMeasurePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.manage');
    }

    public function update(User $user, UnitOfMeasure $unit): bool
    {
        return $this->hasCapability($user, $unit->organization_id, 'catalog.manage');
    }
}
