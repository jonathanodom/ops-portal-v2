<?php

namespace App\Policies;

use App\Models\CustomerServiceEnrollment;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CustomerServiceEnrollmentPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'subscriptions.view');
    }

    public function view(User $user, CustomerServiceEnrollment $enrollment): bool
    {
        return $this->hasCapability($user, $enrollment->organization_id, 'subscriptions.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'subscriptions.manage');
    }

    public function update(User $user, CustomerServiceEnrollment $enrollment): bool
    {
        return $this->hasCapability($user, $enrollment->organization_id, 'subscriptions.manage');
    }
}
