<?php

namespace App\Policies;

use App\Models\CatalogPackage;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CatalogPackagePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.view');
    }

    public function view(User $user, CatalogPackage $package): bool
    {
        return $this->hasCapability($user, $package->organization_id, 'catalog.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.manage');
    }

    public function update(User $user, CatalogPackage $package): bool
    {
        return $this->hasCapability($user, $package->organization_id, 'catalog.manage');
    }
}
