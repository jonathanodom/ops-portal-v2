<?php

namespace App\Policies;

use App\Models\CatalogCategory;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CatalogCategoryPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.view');
    }

    public function view(User $user, CatalogCategory $category): bool
    {
        return $this->hasCapability($user, $category->organization_id, 'catalog.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.manage');
    }

    public function update(User $user, CatalogCategory $category): bool
    {
        return $this->hasCapability($user, $category->organization_id, 'catalog.manage');
    }
}
