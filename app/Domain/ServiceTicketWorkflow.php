<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceTicketWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function changeTicketStatus(ServiceTicket $ticket, string $to, User $actor, ?string $reason = null): ServiceTicket
    {
        $allowed = [
            'open' => ['on_hold', 'canceled'],
            'on_hold' => ['open', 'canceled'],
        ];
        if (! in_array($to, $allowed[$ticket->status] ?? [], true)) {
            $this->rejected($ticket->organization, $actor, 'service_ticket.transition_rejected', $ticket, $ticket->status, $to);
        }
        if (in_array($to, ['on_hold', 'canceled'], true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reason is required for this status change.']);
        }
        if ($to === 'canceled' && $ticket->visits()->whereHas('currentCloseout', fn ($query) => $query->where('status', 'submitted'))->exists()) {
            throw ValidationException::withMessages(['status' => 'A ticket with submitted field evidence cannot be canceled before office disposition.']);
        }

        return DB::transaction(function () use ($ticket, $to, $actor, $reason): ServiceTicket {
            $ticket = ServiceTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $from = $ticket->status;
            $ticket->update([
                'status' => $to,
                'status_reason' => $to === 'open' ? null : $reason,
                'status_changed_at' => now(),
                'status_changed_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            if ($to === 'canceled') {
                $ticket->visits()->where('status', '!=', 'canceled')->lockForUpdate()->get()
                    ->each(function (Visit $visit) use ($actor): void {
                        $fromVisit = $visit->status;
                        $visit->update([
                            'status' => 'canceled',
                            'canceled_at' => now(),
                            'canceled_by_id' => $actor->id,
                            'cancellation_reason' => 'Service ticket canceled',
                            'updated_by_id' => $actor->id,
                        ]);
                        $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.transitioned', $visit, [
                            'from' => $fromVisit,
                            'to' => 'canceled',
                            'ticket_id' => $visit->service_ticket_id,
                        ]);
                    });
            }

            $this->audit->record($ticket->organization, $actor, 'service_ticket.transitioned', $ticket, [
                'from' => $from,
                'to' => $to,
            ]);

            return $ticket->refresh();
        });
    }

    public function executeVisit(Visit $visit, string $to, User $actor): Visit
    {
        $allowed = ['assigned' => 'en_route', 'en_route' => 'on_site'];
        if (($allowed[$visit->status] ?? null) !== $to || $visit->serviceTicket->status !== 'open') {
            $this->rejected($visit->serviceTicket->organization, $actor, 'visit.transition_rejected', $visit, $visit->status, $to);
        }

        return DB::transaction(function () use ($visit, $to, $actor): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $from = $visit->status;
            if (($from === 'assigned' && $to !== 'en_route') || ($from === 'en_route' && $to !== 'on_site')) {
                throw ValidationException::withMessages(['status' => 'The visit changed while you were working. Refresh and try again.']);
            }
            $visit->update(array_merge([
                'status' => $to,
                'updated_by_id' => $actor->id,
            ], $to === 'en_route'
                ? ['en_route_at' => now(), 'en_route_by_id' => $actor->id]
                : ['on_site_at' => now(), 'on_site_by_id' => $actor->id]));
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.transitioned', $visit, [
                'from' => $from,
                'to' => $to,
                'ticket_id' => $visit->service_ticket_id,
            ]);

            return $visit->refresh();
        });
    }

    public function cancelVisit(Visit $visit, User $actor, string $reason): Visit
    {
        if (in_array($visit->status, ['canceled', 'pending_closeout', 'customer_unavailable'], true)) {
            $this->rejected($visit->serviceTicket->organization, $actor, 'visit.transition_rejected', $visit, 'canceled', 'canceled');
        }

        return DB::transaction(function () use ($visit, $actor, $reason): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $from = $visit->status;
            $visit->update([
                'status' => 'canceled',
                'canceled_at' => now(),
                'canceled_by_id' => $actor->id,
                'cancellation_reason' => $reason,
                'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.transitioned', $visit, [
                'from' => $from,
                'to' => 'canceled',
                'ticket_id' => $visit->service_ticket_id,
            ]);

            return $visit->refresh();
        });
    }

    private function rejected(
        Organization $organization,
        User $actor,
        string $event,
        ServiceTicket|Visit $subject,
        string $from,
        string $to,
    ): never {
        $this->audit->record($organization, $actor, $event, $subject, ['from' => $from, 'to' => $to]);
        throw ValidationException::withMessages(['status' => 'That status change is not allowed.']);
    }
}
