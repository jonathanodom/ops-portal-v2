<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class ServiceTicketPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'service_tickets.view');
    }

    public function view(User $user, ServiceTicket $ticket): bool
    {
        return $this->hasCapability($user, $ticket->organization_id, 'service_tickets.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'dispatch.manage');
    }

    public function update(User $user, ServiceTicket $ticket): bool
    {
        return $this->hasCapability($user, $ticket->organization_id, 'dispatch.manage');
    }
}
