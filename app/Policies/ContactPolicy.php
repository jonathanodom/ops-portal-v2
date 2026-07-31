<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class ContactPolicy
{
    use ChecksOrganizationCapability;

    public function create(User $user, Customer $customer): bool
    {
        return $this->hasCapability($user, $customer->organization_id, 'customers.manage');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->hasCapability($user, $contact->organization_id, 'customers.manage');
    }
}
