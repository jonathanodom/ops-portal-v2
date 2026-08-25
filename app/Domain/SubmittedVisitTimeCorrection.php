<?php

namespace App\Domain;

use App\Models\BillingHandoff;
use App\Models\CloseoutReview;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\TimeConflictDiagnostic;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmittedVisitTimeCorrection
{
    public const CAPABILITY = 'visit_time.correct_submitted';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TimeConflictDiagnostic $timeConflictDiagnostic,
    ) {}

    public function correct(
        VisitTimeEntry $timeEntry,
        User $actor,
        CarbonInterface $start,
        CarbonInterface $end,
        string $reason,
    ): VisitTimeEntry {
        return DB::transaction(function () use ($timeEntry, $actor, $start, $end, $reason): VisitTimeEntry {
            $entry = VisitTimeEntry::query()->lockForUpdate()->findOrFail($timeEntry->id);
            $organization = Organization::query()->whereKey($entry->organization_id)->where('active', true)->lockForUpdate()->firstOrFail();
            $visit = Visit::query()->lockForUpdate()->findOrFail($entry->visit_id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $membership = OrganizationMembership::query()
                ->where('organization_id', $entry->organization_id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->with(['roles.capabilities', 'capabilityOverrides'])
                ->lockForUpdate()
                ->first();

            abort_unless(
                $membership
                && $membership->roles->contains('key', 'super_admin')
                && $membership->hasCapability(self::CAPABILITY)
                && (int) $visit->organization_id === (int) $entry->organization_id
                && (int) $ticket->organization_id === (int) $entry->organization_id,
                403,
            );

            if ($entry->active_user_id || ! $entry->ended_at) {
                throw ValidationException::withMessages(['time' => 'Stop the timer before correcting submitted time.']);
            }
            if (! $start->lt($end)) {
                throw ValidationException::withMessages(['ended_at' => 'The end time must be after the start time.']);
            }
            if ($entry->closeout()->value('status') !== 'submitted') {
                throw ValidationException::withMessages(['time' => 'Only submitted Visit time uses this correction workflow. Draft time remains editable through the normal execution controls.']);
            }
            if ($visit->trashed() || $visit->status === 'canceled' || $ticket->status === 'canceled') {
                throw ValidationException::withMessages(['time' => 'Canceled or archived Visit time is read-only.']);
            }

            $approved = CloseoutReview::query()
                ->where('organization_id', $entry->organization_id)
                ->where('decision', 'approved')
                ->whereHas('closeout', fn ($query) => $query->where('visit_id', $visit->id))
                ->exists();
            $hasBillingHandoff = BillingHandoff::query()
                ->where('organization_id', $entry->organization_id)
                ->where('service_ticket_id', $ticket->id)
                ->exists();
            if ($approved || $ticket->status === 'completed' || $hasBillingHandoff) {
                throw ValidationException::withMessages([
                    'time' => 'Submitted time can no longer be corrected because this Visit has already been approved or entered downstream billing. Post-approval reconciliation is a separate workflow.',
                ]);
            }

            $previousStart = $entry->effective_started_at;
            $previousEnd = $entry->effective_ended_at;
            if ($previousStart->equalTo($start) && $previousEnd?->equalTo($end)) {
                throw ValidationException::withMessages(['time' => 'Enter a different interval before saving the correction.']);
            }

            $this->assertNoOverlap($visit, $entry->user_id, $start, $end, $entry->id);
            $sequence = ((int) $entry->corrections()->max('sequence')) + 1;
            $changedFields = collect([
                'corrected_started_at' => ! $previousStart->equalTo($start),
                'corrected_ended_at' => ! $previousEnd?->equalTo($end),
            ])->filter()->keys()->values()->all();

            $entry->corrections()->create([
                'organization_id' => $entry->organization_id,
                'sequence' => $sequence,
                'previous_started_at' => $previousStart,
                'previous_ended_at' => $previousEnd,
                'corrected_started_at' => $start,
                'corrected_ended_at' => $end,
                'reason' => $reason,
                'corrected_by_id' => $actor->id,
            ]);
            $entry->update([
                'corrected_started_at' => $start,
                'corrected_ended_at' => $end,
            ]);
            $this->audit->record($organization, $actor, 'visit_time.submitted_corrected', $entry, [
                'visit_id' => $visit->id,
                'entry_id' => $entry->id,
                'owner_id' => $entry->user_id,
                'sequence' => $sequence,
                'changed_fields' => $changedFields,
            ]);

            return $entry->refresh()->load('corrections.correctedBy');
        });
    }

    private function assertNoOverlap(Visit $contextVisit, int $userId, CarbonInterface $start, CarbonInterface $end, int $exceptId): void
    {
        $overlap = VisitTimeEntry::query()
            ->where('user_id', $userId)
            ->whereKeyNot($exceptId)
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
}
