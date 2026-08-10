<?php

namespace App\Policies;

use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CatalogServicePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.view');
    }

    public function view(User $user, CatalogService $service): bool
    {
        return $this->hasCapability($user, $service->organization_id, 'catalog.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'catalog.manage');
    }

    public function update(User $user, CatalogService $service): bool
    {
        return $this->hasCapability($user, $service->organization_id, 'catalog.manage');
    }

    public function managePricing(User $user, CatalogService $service): bool
    {
        return $this->hasCapability($user, $service->organization_id, 'catalog.pricing.manage');
    }
}
