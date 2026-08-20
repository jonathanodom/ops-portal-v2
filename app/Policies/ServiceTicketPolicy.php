<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMembership;
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

    public function purgeTestData(User $user, ServiceTicket $ticket): bool
    {
        if (! config('field_test.destructive_service_ticket_purge_enabled')) {
            return false;
        }

        $membership = OrganizationMembership::query()
            ->where('organization_id', $ticket->organization_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('active', true))
            ->with(['roles', 'roles.capabilities', 'capabilityOverrides'])
            ->first();

        return $membership !== null
            && $membership->roles->contains('key', 'super_admin')
            && $membership->hasCapability('service_tickets.purge_test_data');
    }
}
