<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CustomerPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->hasCapability($user, $customer->organization_id, 'customers.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'customers.manage');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->hasCapability($user, $customer->organization_id, 'customers.manage');
    }
}
