<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\TimeConflictDiagnostic;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldExecution
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly VisitCreator $visitCreator,
        private readonly CloseoutReadiness $readiness,
        private readonly TimeConflictDiagnostic $timeConflictDiagnostic,
    ) {}

    public function draft(Visit $visit, User $actor): Closeout
    {
        return DB::transaction(function () use ($visit, $actor) {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            if ($visit->current_closeout_id) {
                return Closeout::findOrFail($visit->current_closeout_id);
            } $c = Closeout::create([
                'organization_id' => $visit->organization_id,
                'visit_id' => $visit->id,
                'version' => 1,
                'status' => 'draft',
                'content_version' => 1,
                'last_saved_by_id' => $actor->id,
            ]);
            $visit->update(['current_closeout_id' => $c->id]);
            $this->audit->record($visit->serviceTicket->organization, $actor, 'closeout.draft_created', $c, ['visit_id' => $visit->id]);

            return $c;
        });
    }

    public function save(Closeout $c, array $data, int $version, User $actor): ?Closeout
    {
        if ($c->status !== 'draft') {
            throw ValidationException::withMessages(['closeout' => 'Submitted closeouts are immutable.']);
        } $updated = Closeout::query()->whereKey($c->id)->where('content_version', $version)->update(array_merge($data, ['content_version' => $version + 1, 'last_saved_by_id' => $actor->id, 'updated_at' => now()]));
        if (! $updated) {
            return null;
        } $c = $c->fresh();
        $this->audit->record($c->visit->serviceTicket->organization, $actor, 'closeout.draft_saved', $c, ['changed_fields' => array_keys($data)]);

        return $c;
    }

    public function startTimer(Visit $v, Closeout $c, User $u, string $category): VisitTimeEntry
    {
        if ($c->status !== 'draft') {
            throw ValidationException::withMessages(['time' => 'Closeout is submitted.']);
        }
        $allowed = match ($v->status) {
            'en_route' => ['travel', 'other'],
            'on_site' => ['travel', 'on_site', 'other'],
            default => [],
        };
        if (! in_array($category, $allowed, true)) {
            throw ValidationException::withMessages(['time' => 'That timer is not available at the current visit status.']);
        }
        if (VisitTimeEntry::query()->where('active_user_id', $u->id)->exists()) {
            throw ValidationException::withMessages(['time' => 'Stop your active timer first.']);
        }

        $entry = VisitTimeEntry::create(['organization_id' => $v->organization_id, 'visit_id' => $v->id, 'closeout_id' => $c->id, 'user_id' => $u->id, 'active_user_id' => $u->id, 'category' => $category, 'started_at' => now(), 'source' => 'timer']);
        $this->audit->record($v->serviceTicket->organization, $u, 'visit_time.started', $entry, [
            'visit_id' => $v->id,
            'category' => $category,
        ]);

        return $entry;
    }

    public function transition(Visit $visit, User $actor, string $status, ServiceTicketWorkflow $workflow): Visit
    {
        $workflow->assertVisitExecutionAllowed($visit, $status, $actor);

        return DB::transaction(function () use ($visit, $actor, $status, $workflow): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $active = VisitTimeEntry::query()->where('active_user_id', $actor->id)->lockForUpdate()->first();
            if ($active && $active->visit_id !== $visit->id) {
                throw ValidationException::withMessages(['time' => 'Stop the timer on your other visit before changing this visit status.']);
            }

            $workflow->executeVisit($visit, $status, $actor);
            $visit->refresh();
            $closeout = $this->draft($visit, $actor);
            if ($active) {
                $this->stopTimer($active, $actor);
            }
            $this->startTimer($visit, $closeout, $actor, $status === 'en_route' ? 'travel' : 'on_site');

            return $visit->refresh();
        });
    }

    public function createManualTime(
        Visit $visit,
        Closeout $closeout,
        User $owner,
        User $actor,
        string $category,
        CarbonInterface $start,
        CarbonInterface $end,
        string $reason,
    ): VisitTimeEntry {
        return DB::transaction(function () use ($visit, $closeout, $owner, $actor, $category, $start, $end, $reason): VisitTimeEntry {
            $closeout = Closeout::query()->lockForUpdate()->findOrFail($closeout->id);
            if ($closeout->status !== 'draft') {
                throw ValidationException::withMessages(['time' => 'Submitted closeout time is immutable.']);
            }
            $this->assertNoOverlap($visit, $owner->id, $start, $end);
            $entry = VisitTimeEntry::query()->create([
                'organization_id' => $visit->organization_id,
                'visit_id' => $visit->id,
                'closeout_id' => $closeout->id,
                'user_id' => $owner->id,
                'category' => $category,
                'started_at' => $start,
                'ended_at' => $end,
                'source' => 'manual',
                'correction_reason' => $reason,
            ]);
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit_time.created_manually', $entry, [
                'visit_id' => $visit->id,
                'owner_id' => $owner->id,
                'category' => $category,
                'changed_fields' => ['started_at', 'ended_at', 'category'],
            ]);

            return $entry;
        });
    }

    public function correctTime(
        VisitTimeEntry $entry,
        User $actor,
        CarbonInterface $start,
        CarbonInterface $end,
        string $reason,
    ): VisitTimeEntry {
        return DB::transaction(function () use ($entry, $actor, $start, $end, $reason): VisitTimeEntry {
            $entry = VisitTimeEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->closeout->status !== 'draft') {
                throw ValidationException::withMessages(['time' => 'Submitted closeout time is immutable.']);
            }
            if ($entry->active_user_id || ! $entry->ended_at) {
                throw ValidationException::withMessages(['time' => 'Stop the timer before correcting it.']);
            }
            $this->assertNoOverlap($entry->visit, $entry->user_id, $start, $end, $entry->id);
            $changed = collect(['started_at', 'ended_at'])->filter(function (string $field) use ($entry, $start, $end): bool {
                $value = $field === 'started_at' ? $start : $end;

                return ! $entry->$field?->equalTo($value);
            })->values()->all();
            $entry->update([
                'started_at' => $start,
                'ended_at' => $end,
                'correction_reason' => $reason,
                'active_user_id' => null,
                'source' => 'manual',
            ]);
            $this->audit->record($entry->closeout->visit->serviceTicket->organization, $actor, 'visit_time.corrected', $entry, [
                'visit_id' => $entry->visit_id,
                'owner_id' => $entry->user_id,
                'changed_fields' => $changed,
            ]);

            return $entry->refresh();
        });
    }

    public function stopTimer(VisitTimeEntry $e, User $u, string $source = 'timer'): void
    {
        $e->update(['ended_at' => now(), 'active_user_id' => null, 'source' => $source]);
        $this->audit->record($e->closeout->visit->serviceTicket->organization, $u, 'visit_time.stopped', $e, [
            'visit_id' => $e->visit_id,
            'category' => $e->category,
            'source' => $source,
        ]);
    }

    public function submit(Visit $v, Closeout $c, User $actor, string $token, bool $administrative = false): Closeout
    {
        if ($c->status === 'submitted' && $c->submitted_token === $token) {
            return $c;
        } if ($c->status !== 'draft') {
            throw ValidationException::withMessages(['submit' => 'Already submitted.']);
        } $m = OrganizationMembership::where('organization_id', $v->organization_id)->where('user_id', $actor->id)->where('status', 'active')->firstOrFail();
        $lead = $v->assignments()->where('organization_membership_id', $m->id)->where('is_lead', true)->exists();
        $executeAny = $m->hasCapability('visits.execute_any');
        $manualCompletion = $administrative && $m->hasCapability('closeouts.manual_complete');
        if (! $lead && ! $executeAny && ! $manualCompletion) {
            $this->audit->record($v->serviceTicket->organization, $actor, 'closeout.submit_rejected', $c, ['visit_id' => $v->id]);
            abort(403);
        }

        return DB::transaction(function () use ($v, $c, $actor, $token, $executeAny, $manualCompletion) {
            $c = Closeout::lockForUpdate()->findOrFail($c->id);
            if ($c->status === 'submitted' && $c->submitted_token === $token) {
                return $c;
            }
            if ($c->status !== 'draft') {
                throw ValidationException::withMessages(['submit' => 'Already submitted.']);
            }
            $this->validateSubmission($c);
            $c->timeEntries()->whereNull('ended_at')->update(['ended_at' => now(), 'active_user_id' => null, 'source' => 'system_auto']);
            $return = null;
            if ($c->outcome === 'needs_return_trip') {
                $return = $c->return_visit_id ? Visit::query()->find($c->return_visit_id) : null;
                $return ??= $this->visitCreator->create($v->serviceTicket, ['service_location_id' => $v->service_location_id, 'return_of_visit_id' => $v->id, 'status' => 'planned', 'timezone' => $v->timezone, 'return_reason' => $c->return_reason, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
            } if ($c->outcome === 'on_hold') {
                ServiceTicket::whereKey($v->service_ticket_id)->update(['status' => 'on_hold', 'status_reason' => $c->hold_reason, 'status_changed_at' => now(), 'status_changed_by_id' => $actor->id]);
            } $c->update(['status' => 'submitted', 'submitted_token' => $token, 'submitted_by_id' => $actor->id, 'submitted_at' => now(), 'acknowledged_at' => filled($c->representative_name) ? ($c->acknowledged_at ?? now()) : null, 'return_visit_id' => $return?->id]);
            $v->update(['status' => $c->outcome === 'customer_unavailable' ? 'customer_unavailable' : 'pending_closeout', 'updated_by_id' => $actor->id]);
            $this->audit->record($v->serviceTicket->organization, $actor, 'closeout.submitted', $c, ['visit_id' => $v->id, 'outcome' => $c->outcome, 'return_visit_id' => $return?->id, 'execute_any_override' => $executeAny, 'administrative_override' => $manualCompletion]);

            return $c->fresh();
        });
    }

    private function validateSubmission(Closeout $c): void
    {
        $errors = $this->readiness->errors($c);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNoOverlap(Visit $contextVisit, int $userId, CarbonInterface $start, CarbonInterface $end, ?int $exceptId = null): void
    {
        $overlap = VisitTimeEntry::query()
            ->where('user_id', $userId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->where('started_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->orderBy('started_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($overlap) {
            throw ValidationException::withMessages(['time' => $this->timeConflictDiagnostic->message($overlap, $contextVisit)]);
        }
    }
}
