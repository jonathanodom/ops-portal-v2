<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
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
        private readonly ReturnFollowUpCreator $returnFollowUps,
        private readonly CloseoutReadiness $readiness,
        private readonly TimeConflictDiagnostic $timeConflictDiagnostic,
        private readonly ServiceTicketWorkItemWorkflow $workItems,
        private readonly WorkItemTimeAttribution $attribution,
        private readonly CloseoutAcknowledgmentSignatureCapture $signatureCapture,
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

    public function startTimer(Visit $v, Closeout $c, User $u, string $category, ?ServiceTicketWorkItem $workItem = null): VisitTimeEntry
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
        $this->assertWorkItemTarget($v, $category, $workItem);

        $entry = VisitTimeEntry::create(['organization_id' => $v->organization_id, 'visit_id' => $v->id, 'closeout_id' => $c->id, 'service_ticket_work_item_id' => $workItem?->id, 'user_id' => $u->id, 'active_user_id' => $u->id, 'category' => $category, 'started_at' => now(), 'source' => 'timer']);
        if ($workItem) {
            $this->workItems->touch($workItem, $v, $u);
        }
        $this->audit->record($v->serviceTicket->organization, $u, 'visit_time.started', $entry, [
            'visit_id' => $v->id,
            'category' => $category,
            'work_item_id' => $workItem?->id,
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
        ?ServiceTicketWorkItem $workItem = null,
    ): VisitTimeEntry {
        return DB::transaction(function () use ($visit, $closeout, $owner, $actor, $category, $start, $end, $reason, $workItem): VisitTimeEntry {
            $closeout = Closeout::query()->lockForUpdate()->findOrFail($closeout->id);
            if ($closeout->status !== 'draft') {
                throw ValidationException::withMessages(['time' => 'Submitted closeout time is immutable.']);
            }
            $this->assertNoOverlap($visit, $owner->id, $start, $end);
            $this->assertWorkItemTarget($visit, $category, $workItem);
            $entry = VisitTimeEntry::query()->create([
                'organization_id' => $visit->organization_id,
                'visit_id' => $visit->id,
                'closeout_id' => $closeout->id,
                'service_ticket_work_item_id' => $workItem?->id,
                'user_id' => $owner->id,
                'category' => $category,
                'started_at' => $start,
                'ended_at' => $end,
                'source' => 'manual',
                'correction_reason' => $reason,
            ]);
            if ($workItem) {
                $this->workItems->touch($workItem, $visit, $actor);
            }
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit_time.created_manually', $entry, [
                'visit_id' => $visit->id,
                'owner_id' => $owner->id,
                'category' => $category,
                'changed_fields' => ['started_at', 'ended_at', 'category'],
                'work_item_id' => $workItem?->id,
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
            $this->attribution->assertFits($entry, (int) $start->diffInSeconds($end));
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
            'work_item_id' => $e->service_ticket_work_item_id,
        ]);
    }

    public function switchWorkFocus(Visit $visit, User $actor, ?ServiceTicketWorkItem $target): VisitTimeEntry
    {
        return DB::transaction(function () use ($visit, $actor, $target): VisitTimeEntry {
            $visit = Visit::query()->lockForUpdate()->with('currentCloseout')->findOrFail($visit->id);
            $active = VisitTimeEntry::query()->where('active_user_id', $actor->id)->lockForUpdate()->first();
            if (! $active || (int) $active->visit_id !== (int) $visit->id || $active->category !== 'on_site') {
                throw ValidationException::withMessages(['time' => 'An active on-site timer is required to switch work focus.']);
            }
            $this->assertWorkItemTarget($visit, 'on_site', $target);
            if ((int) ($active->service_ticket_work_item_id ?? 0) === (int) ($target?->id ?? 0)) {
                return $active;
            }
            $boundary = now();
            $active->update(['ended_at' => $boundary, 'active_user_id' => null]);
            $next = VisitTimeEntry::query()->create([
                'organization_id' => $active->organization_id, 'visit_id' => $active->visit_id, 'closeout_id' => $active->closeout_id,
                'service_ticket_work_item_id' => $target?->id, 'user_id' => $active->user_id, 'active_user_id' => $active->user_id,
                'category' => 'on_site', 'started_at' => $boundary, 'source' => 'timer',
            ]);
            if ($target) {
                $this->workItems->touch($target, $visit, $actor);
            }
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit_time.work_focus_switched', $next, [
                'visit_id' => $visit->id, 'ended_entry_id' => $active->id, 'started_entry_id' => $next->id,
                'from_work_item_id' => $active->service_ticket_work_item_id, 'to_work_item_id' => $target?->id,
            ]);

            return $next;
        });
    }

    public function submit(Visit $v, Closeout $c, User $actor, string $token, bool $administrative = false, ?string $signaturePayload = null): Closeout
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

        if (filled($c->ack_unavailable_category) && filled($signaturePayload)) {
            throw ValidationException::withMessages(['signature_data' => 'Remove the signature when an acknowledgment fallback is selected.']);
        }
        $decodedSignature = filled($signaturePayload) ? $this->signatureCapture->decode($signaturePayload) : null;
        $storedSignature = null;
        try {
            return DB::transaction(function () use ($v, $c, $actor, $token, $executeAny, $manualCompletion, $decodedSignature, &$storedSignature) {
                $c = Closeout::lockForUpdate()->findOrFail($c->id);
                if ($c->status === 'submitted' && $c->submitted_token === $token) {
                    return $c;
                }
                if ($c->status !== 'draft') {
                    throw ValidationException::withMessages(['submit' => 'Already submitted.']);
                }
                $this->validateSubmission($c, $decodedSignature !== null, ! $manualCompletion);
                if ($decodedSignature) {
                    $storedSignature = $this->signatureCapture->store($c, $actor, $decodedSignature);
                }
                $c->timeEntries()->whereNull('ended_at')->update(['ended_at' => now(), 'active_user_id' => null, 'source' => 'system_auto']);
                $returnFollowUp = null;
                if ($c->outcome === 'needs_return_trip') {
                    $returnFollowUp = $this->returnFollowUps->create($c, $actor);
                } if ($c->outcome === 'on_hold') {
                    ServiceTicket::whereKey($v->service_ticket_id)->update(['status' => 'on_hold', 'status_reason' => $c->hold_reason, 'status_changed_at' => now(), 'status_changed_by_id' => $actor->id]);
                } $c->update(['status' => 'submitted', 'submitted_token' => $token, 'submitted_by_id' => $actor->id, 'submitted_at' => now(), 'acknowledged_at' => filled($c->representative_name) ? ($c->acknowledged_at ?? now()) : null, 'return_visit_id' => null]);
                $v->update(['status' => $c->outcome === 'customer_unavailable' ? 'customer_unavailable' : 'pending_closeout', 'updated_by_id' => $actor->id]);
                $this->audit->record($v->serviceTicket->organization, $actor, 'closeout.submitted', $c, ['visit_id' => $v->id, 'outcome' => $c->outcome, 'return_follow_up_ticket_id' => $returnFollowUp?->id, 'execute_any_override' => $executeAny, 'administrative_override' => $manualCompletion]);

                return $c->fresh('acknowledgmentSignature');
            });
        } catch (\Throwable $exception) {
            if ($storedSignature) {
                $this->signatureCapture->deleteObject($storedSignature);
            }
            throw $exception;
        }
    }

    private function validateSubmission(Closeout $c, bool $signatureProvided, bool $requireSignature): void
    {
        $errors = $this->readiness->errors($c, $signatureProvided, $requireSignature);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertNoOverlap(Visit $contextVisit, int $userId, CarbonInterface $start, CarbonInterface $end, ?int $exceptId = null): void
    {
        $overlap = VisitTimeEntry::query()
            ->where('user_id', $userId)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('COALESCE(corrected_started_at, started_at) < ?', [$end])
            ->where(function ($query) use ($start): void {
                $query->whereRaw('COALESCE(corrected_ended_at, ended_at) IS NULL')
                    ->orWhereRaw('COALESCE(corrected_ended_at, ended_at) > ?', [$start]);
            })
            ->orderByRaw('COALESCE(corrected_started_at, started_at)')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        if ($overlap) {
            throw ValidationException::withMessages(['time' => $this->timeConflictDiagnostic->message($overlap, $contextVisit)]);
        }
    }

    private function assertWorkItemTarget(Visit $visit, string $category, ?ServiceTicketWorkItem $workItem): void
    {
        if (! $workItem) {
            return;
        }
        if ($category === 'travel') {
            throw ValidationException::withMessages(['work_item' => 'Travel time must remain on Primary Ticket scope.']);
        }
        abort_unless((int) $workItem->organization_id === (int) $visit->organization_id
            && (int) $workItem->service_ticket_id === (int) $visit->service_ticket_id, 404);
        if (! in_array($workItem->status, ['open', 'needs_follow_up'], true)) {
            throw ValidationException::withMessages(['work_item' => 'Choose an Open or Needs follow-up Work Item.']);
        }
        if (! $visit->currentCloseout || $visit->currentCloseout->status !== 'draft') {
            throw ValidationException::withMessages(['work_item' => 'A current draft Closeout is required.']);
        }
    }
}
