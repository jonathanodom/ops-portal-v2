<?php

namespace App\Domain;

use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitArchiveWorkflow
{
    private const ARCHIVABLE_STATUSES = ['planned', 'scheduled', 'assigned', 'canceled'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly ServiceTicketCompletion $ticketCompletion,
    ) {}

    public function archive(Visit $visit, User $actor, string $reason): Visit
    {
        return DB::transaction(function () use ($visit, $actor, $reason): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $this->assertArchivable($visit);
            $visit->forceFill([
                'archived_by_id' => $actor->id,
                'archive_reason' => $reason,
                'restored_by_id' => null,
                'restored_at' => null,
            ])->save();
            $ticket = $visit->serviceTicket;
            $this->audit->record($ticket->organization, $actor, 'visit.archived', $visit, [
                'ticket_id' => $visit->service_ticket_id,
                'visit_id' => $visit->id,
                'status' => $visit->status,
                'changed_fields' => ['deleted_at', 'archived_by_id', 'archive_reason'],
            ]);
            $visit->delete();
            $this->ticketCompletion->completeIfEligible($ticket, $actor);

            return $visit;
        });
    }

    public function restore(Visit $visit, User $actor): Visit
    {
        return DB::transaction(function () use ($visit, $actor): Visit {
            $visit = Visit::onlyTrashed()->lockForUpdate()->findOrFail($visit->id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            if ($visit->status !== 'canceled' && ! in_array($ticket->status, ['open', 'on_hold'], true)) {
                $this->reject('visit', 'Only canceled visits may be restored to completed or canceled Service Tickets.');
            }
            if ($visit->status !== 'canceled') {
                $visit->load('assignments.membership');
                foreach ($visit->assignments as $assignment) {
                    $membership = $assignment->membership;
                    if (! $membership || $membership->status !== 'active' || ! $membership->hasCapability('experience.field.access')) {
                        $this->reject('assignments', 'Restore is blocked because an assigned crew membership is no longer valid.');
                    }
                }
            }
            $visit->restore();
            $visit->forceFill(['restored_by_id' => $actor->id, 'restored_at' => now()])->save();
            $this->audit->record($ticket->organization, $actor, 'visit.restored', $visit, [
                'ticket_id' => $ticket->id,
                'visit_id' => $visit->id,
                'status' => $visit->status,
                'changed_fields' => ['deleted_at', 'restored_by_id', 'restored_at'],
            ]);

            return $visit;
        });
    }

    public function purge(Visit $visit, User $actor): void
    {
        DB::transaction(function () use ($visit, $actor): void {
            $visit = Visit::onlyTrashed()->lockForUpdate()->findOrFail($visit->id);
            $blockers = $this->purgeBlockers($visit);
            if ($blockers !== []) {
                $this->reject('visit', 'This archived visit contains operational evidence or linked records and cannot be permanently deleted.');
            }
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->audit->record($ticket->organization, $actor, 'visit.purged', $ticket, [
                'ticket_id' => $ticket->id,
                'visit_id' => $visit->id,
                'status' => $visit->status,
            ]);
            $visit->forceDelete();
        });
    }

    public function canPurge(Visit $visit): bool
    {
        return $visit->trashed() && $this->purgeBlockers($visit) === [];
    }

    /** @return array<int, string> */
    public function purgeBlockers(Visit $visit): array
    {
        $blockers = [];
        if ($visit->en_route_at || $visit->on_site_at) {
            $blockers[] = 'execution_timestamps';
        }
        if ($visit->timeEntries()->exists()) {
            $blockers[] = 'time_entries';
        }
        if (Closeout::query()->where('visit_id', $visit->id)->exists()) {
            $blockers[] = 'closeouts';
        }
        if (BillingHandoff::query()->where('visit_id', $visit->id)->exists()) {
            $blockers[] = 'billing_handoff';
        }
        if (Visit::withTrashed()->where('return_of_visit_id', $visit->id)->exists()) {
            $blockers[] = 'return_visits';
        }
        if (Closeout::query()->where('return_visit_id', $visit->id)->exists()) {
            $blockers[] = 'closeout_return_reference';
        }

        return $blockers;
    }

    private function assertArchivable(Visit $visit): void
    {
        if (! in_array($visit->status, self::ARCHIVABLE_STATUSES, true)) {
            $this->reject('status', 'Only unused or canceled visits may be archived.');
        }
        if ($visit->timeEntries()->whereNull('ended_at')->exists()) {
            $this->reject('active_timers', 'Stop or cancel every active timer before archiving this visit.');
        }
        if ($visit->currentCloseout()->where('status', 'submitted')->exists()) {
            $this->reject('closeout', 'A visit with a submitted closeout cannot be archived.');
        }
        if ($visit->returnVisits()->exists()) {
            $this->reject('return_visits', 'Archive or resolve later return visits before archiving their source visit.');
        }
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
