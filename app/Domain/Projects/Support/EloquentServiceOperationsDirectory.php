<?php

namespace App\Domain\Projects\Support;

use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\Projects\Data\TicketSummary;
use App\Models\Organization;
use App\Models\ServiceTicket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentServiceOperationsDirectory implements ServiceOperationsDirectory
{
    public function forCustomer(Organization $organization, int $customerId): Collection
    {
        return $this->query($organization)->where('customer_id', $customerId)->latest('updated_at')->limit(200)->get()->mapWithKeys(fn (ServiceTicket $ticket) => [$ticket->id => $this->ticket($ticket)]);
    }

    public function summaries(Organization $organization, array $ids): Collection
    {
        return $this->query($organization)->whereKey($ids)->get()->mapWithKeys(fn (ServiceTicket $ticket) => [$ticket->id => $this->ticket($ticket)]);
    }

    public function resolve(Organization $organization, int $ticketId): TicketSummary
    {
        return $this->ticket($this->query($organization)->findOrFail($ticketId));
    }

    private function query(Organization $organization)
    {
        return ServiceTicket::query()->forOrganization($organization->id)->with('serviceLocation:id,name');
    }

    private function ticket(ServiceTicket $ticket): TicketSummary
    {
        return new TicketSummary(
            $ticket->id, $ticket->customer_id, $ticket->service_location_id, $ticket->ticket_number,
            $ticket->title, $ticket->purpose, $ticket->priority, $ticket->status,
            $ticket->serviceLocation->name,
            CarbonImmutable::instance($ticket->updated_at),
        );
    }
}
