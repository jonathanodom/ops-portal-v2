<?php

namespace App\Domain;

use App\Models\ServiceTicket;
use App\Models\Visit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class VisitCreator
{
    /** @param array<string, mixed> $values */
    public function create(ServiceTicket $ticket, array $values): Visit
    {
        return DB::transaction(function () use ($ticket, $values): Visit {
            $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $number = $this->consumeNextNumber($ticket);

            return Visit::query()->create(Arr::except($values, [
                'organization_id', 'service_ticket_id', 'service_location_id', 'ticket_visit_number',
            ]) + [
                'organization_id' => $ticket->organization_id,
                'service_ticket_id' => $ticket->id,
                'service_location_id' => $ticket->service_location_id,
                'ticket_visit_number' => $number,
            ]);
        });
    }

    public function assignNumber(Visit $visit): void
    {
        DB::transaction(function () use ($visit): void {
            $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($visit->service_ticket_id);
            $visit->ticket_visit_number = $this->consumeNextNumber($ticket);
        });
    }

    private function consumeNextNumber(ServiceTicket $ticket): int
    {
        $number = max(1, (int) $ticket->next_visit_number);
        $ticket->forceFill(['next_visit_number' => $number + 1])->save();

        return $number;
    }
}
