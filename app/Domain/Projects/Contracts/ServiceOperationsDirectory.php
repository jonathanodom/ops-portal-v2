<?php

namespace App\Domain\Projects\Contracts;

use App\Domain\Projects\Data\TicketSummary;
use App\Models\Organization;
use Illuminate\Support\Collection;

interface ServiceOperationsDirectory
{
    /** @return Collection<int, TicketSummary> */
    public function forCustomer(Organization $organization, int $customerId): Collection;

    /** @return Collection<int, TicketSummary> */
    public function summaries(Organization $organization, array $ids): Collection;

    public function resolve(Organization $organization, int $ticketId): TicketSummary;
}
