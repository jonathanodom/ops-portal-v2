<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceTicketWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function changeTicketStatus(ServiceTicket $ticket, string $to, User $actor, ?string $reason = null, bool $confirmStopActiveTimers = false): ServiceTicket
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
        if ($to === 'canceled') {
            $this->requireTimerConfirmation($ticket->organization, $actor, $ticket->visits()->pluck('id'), $confirmStopActiveTimers, $ticket);
        }

        return DB::transaction(function () use ($ticket, $to, $actor, $reason, $confirmStopActiveTimers): ServiceTicket {
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
                $visits = $ticket->visits()->where('status', '!=', 'canceled')->lockForUpdate()->get();
                $this->stopCancellationTimers($ticket->organization, $actor, $visits->pluck('id'), $confirmStopActiveTimers, 'ticket_canceled');
                $visits
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
        $this->assertVisitExecutionAllowed($visit, $to, $actor);

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

    public function assertVisitExecutionAllowed(Visit $visit, string $to, User $actor): void
    {
        $allowed = ['assigned' => 'en_route', 'en_route' => 'on_site'];
        if (($allowed[$visit->status] ?? null) !== $to || $visit->serviceTicket->status !== 'open') {
            $this->rejected($visit->serviceTicket->organization, $actor, 'visit.transition_rejected', $visit, $visit->status, $to);
        }
    }

    public function cancelVisit(Visit $visit, User $actor, string $reason, bool $confirmStopActiveTimers = false): Visit
    {
        if (in_array($visit->status, ['canceled', 'pending_closeout', 'customer_unavailable'], true)) {
            $this->rejected($visit->serviceTicket->organization, $actor, 'visit.transition_rejected', $visit, 'canceled', 'canceled');
        }
        $this->requireTimerConfirmation($visit->serviceTicket->organization, $actor, collect([$visit->id]), $confirmStopActiveTimers, $visit);

        return DB::transaction(function () use ($visit, $actor, $reason, $confirmStopActiveTimers): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $this->stopCancellationTimers($visit->serviceTicket->organization, $actor, collect([$visit->id]), $confirmStopActiveTimers, 'visit_canceled');
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

    private function requireTimerConfirmation(Organization $organization, User $actor, $visitIds, bool $confirmed, ServiceTicket|Visit $subject): void
    {
        $count = VisitTimeEntry::query()->whereIn('visit_id', $visitIds)->whereNull('ended_at')->count();
        if ($count && ! $confirmed) {
            $this->audit->record($organization, $actor, 'visit.cancellation_rejected', $subject, [
                'reason_code' => 'active_timers_require_confirmation',
                'active_timer_count' => $count,
            ]);
            throw ValidationException::withMessages([
                'confirm_stop_active_timers' => "{$count} active timer(s) will be stopped. Confirm this before canceling.",
            ]);
        }
    }

    private function stopCancellationTimers(Organization $organization, User $actor, $visitIds, bool $confirmed, string $reasonCode): void
    {
        $entries = VisitTimeEntry::query()->whereIn('visit_id', $visitIds)->whereNull('ended_at')->lockForUpdate()->get();
        if ($entries->isNotEmpty() && ! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_stop_active_timers' => 'An active timer appeared while canceling. Review and confirm the cancellation again.',
            ]);
        }
        $stoppedAt = now();
        foreach ($entries as $entry) {
            $entry->update(['ended_at' => $stoppedAt, 'active_user_id' => null, 'source' => 'system_auto']);
            $this->audit->record($organization, $actor, 'visit_time.stopped', $entry, [
                'visit_id' => $entry->visit_id,
                'category' => $entry->category,
                'source' => 'system_auto',
                'reason_code' => $reasonCode,
            ]);
        }
        if ($entries->isNotEmpty()) {
            $this->audit->record($organization, $actor, 'visit.cancellation_timer_stop_confirmed', $entries->first()->closeout->visit, [
                'active_timer_count' => $entries->count(),
                'reason_code' => $reasonCode,
            ]);
        }
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
