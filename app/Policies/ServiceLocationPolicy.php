<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class ServiceLocationPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'customers.view');
    }

    public function view(User $user, ServiceLocation $location): bool
    {
        return $this->hasCapability($user, $location->organization_id, 'customers.view');
    }

    public function create(User $user, Customer $customer): bool
    {
        return $this->hasCapability($user, $customer->organization_id, 'customers.manage');
    }

    public function update(User $user, ServiceLocation $location): bool
    {
        return $this->hasCapability($user, $location->organization_id, 'customers.manage');
    }
}
