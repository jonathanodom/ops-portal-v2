<?php

namespace App\Policies;

use App\Models\CatalogProduct;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CatalogProductPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.view');
    }

    public function view(User $user, CatalogProduct $product): bool
    {
        return $this->hasCapability($user, $product->organization_id, 'catalog.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.manage');
    }

    public function update(User $user, CatalogProduct $product): bool
    {
        return $this->hasCapability($user, $product->organization_id, 'catalog.manage');
    }
}
