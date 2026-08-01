<?php

namespace App\Domain;

use App\Events\BillingHandoffCreated;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitPartProposal;
use App\Support\AuditRecorder;
use App\Support\IncidentRecorder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseoutReviewWorkflow
{
    private const COPY_FIELDS = [
        'outcome', 'diagnosis', 'work_performed', 'exceptions', 'recommendations',
        'return_reason', 'unfinished_work', 'needed_equipment', 'hold_reason',
        'unavailable_category', 'unavailable_detail', 'representative_name',
        'acknowledged_at', 'ack_unavailable_category', 'ack_unavailable_detail',
        'no_photo_category', 'no_photo_detail', 'return_visit_id',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function returnForCorrection(Closeout $closeout, User $actor, string $reason, string $token): CloseoutReview
    {
        if ($existing = CloseoutReview::query()->where('decision_token', $token)->first()) {
            if ((int) $existing->closeout_id !== (int) $closeout->id || $existing->decision !== 'returned') {
                throw ValidationException::withMessages(['decision_token' => 'That decision token has already been used.']);
            }

            return $existing;
        }
        $selfReview = $this->authorizeReviewer($closeout, $actor);

        return DB::transaction(function () use ($closeout, $actor, $reason, $token, $selfReview): CloseoutReview {
            $closeout = Closeout::query()->lockForUpdate()->findOrFail($closeout->id);
            $this->assertReviewable($closeout);
            $review = CloseoutReview::query()->create([
                'organization_id' => $closeout->organization_id,
                'closeout_id' => $closeout->id,
                'reviewer_id' => $actor->id,
                'decision' => 'returned',
                'reason' => $reason,
                'self_review_override' => $selfReview,
                'decision_token' => $token,
                'decided_at' => now(),
            ]);

            $copy = Arr::only($closeout->getAttributes(), self::COPY_FIELDS);
            $next = Closeout::query()->create($copy + [
                'organization_id' => $closeout->organization_id,
                'visit_id' => $closeout->visit_id,
                'parent_closeout_id' => $closeout->id,
                'version' => $closeout->version + 1,
                'status' => 'draft',
                'content_version' => 1,
                'last_saved_by_id' => $actor->id,
            ]);
            $closeout->parts()->whereNull('removed_at')->each(function (VisitPartProposal $part) use ($next): void {
                $next->parts()->create([
                    'organization_id' => $part->organization_id,
                    'visit_id' => $part->visit_id,
                    'source_proposal_id' => $part->id,
                    'proposed_by_id' => $part->proposed_by_id,
                    'description' => $part->description,
                    'quantity' => $part->quantity,
                    'unit' => $part->unit,
                    'serial_mac' => $part->serial_mac,
                    'billing_treatment' => $part->billing_treatment,
                    'technician_note' => $part->technician_note,
                ]);
            });
            $closeout->visit()->update(['current_closeout_id' => $next->id, 'status' => 'returned_for_correction', 'updated_by_id' => $actor->id]);
            $this->audit->record($closeout->visit->serviceTicket->organization, $actor, 'closeout.returned', $closeout, [
                'visit_id' => $closeout->visit_id,
                'next_closeout_id' => $next->id,
                'from_version' => $closeout->version,
                'to_version' => $next->version,
                'self_review_override' => $selfReview,
            ]);

            return $review;
        });
    }

    /** @param array<int, array<string, mixed>> $timeAdjustments @param array<int, array<string, mixed>> $partAdjustments */
    public function approve(Closeout $closeout, User $actor, string $token, ?string $disposition, ?string $dispositionReason, array $timeAdjustments, array $partAdjustments): CloseoutReview
    {
        if ($existing = CloseoutReview::query()->where('decision_token', $token)->first()) {
            if ((int) $existing->closeout_id !== (int) $closeout->id || $existing->decision !== 'approved') {
                throw ValidationException::withMessages(['decision_token' => 'That decision token has already been used.']);
            }

            return $existing;
        }
        $selfReview = $this->authorizeReviewer($closeout, $actor);
        if ($disposition === 'cancel') {
            $activeTimerCount = $closeout->visit->serviceTicket->visits()->whereHas('timeEntries', fn ($query) => $query->whereNull('ended_at'))->withCount(['timeEntries as active_timer_count' => fn ($query) => $query->whereNull('ended_at')])->get()->sum('active_timer_count');
            if ($activeTimerCount) {
                app(IncidentRecorder::class)->record($closeout->visit->serviceTicket->organization, $actor, 'transition_failure', 'error', $closeout->visit->serviceTicket, [
                    'reason_code' => 'submitted_closeout_active_timer',
                    'ticket_id' => $closeout->visit->service_ticket_id,
                    'active_timer_count' => $activeTimerCount,
                ]);
                throw ValidationException::withMessages(['disposition' => 'Cancellation is blocked because submitted work has an active timer.']);
            }
        }

        return DB::transaction(function () use ($closeout, $actor, $token, $disposition, $dispositionReason, $timeAdjustments, $partAdjustments, $selfReview): CloseoutReview {
            $closeout = Closeout::query()->lockForUpdate()->findOrFail($closeout->id);
            $this->assertReviewable($closeout);
            $visit = Visit::query()->lockForUpdate()->findOrFail($closeout->visit_id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->validateDisposition($closeout, $disposition, $dispositionReason);

            $review = CloseoutReview::query()->create([
                'organization_id' => $closeout->organization_id,
                'closeout_id' => $closeout->id,
                'reviewer_id' => $actor->id,
                'decision' => 'approved',
                'disposition' => $disposition,
                'disposition_reason' => $dispositionReason,
                'self_review_override' => $selfReview,
                'decision_token' => $token,
                'decided_at' => now(),
            ]);
            $this->storeAdjustments($review, $closeout, $timeAdjustments, $partAdjustments);

            if ($closeout->outcome === 'customer_unavailable') {
                $this->applyUnavailableDisposition($closeout, $actor, $disposition, $dispositionReason);
            } else {
                $visit->update(['status' => 'approved', 'updated_by_id' => $actor->id]);
            }

            if ($closeout->outcome === 'resolved' && ! $ticket->visits()->whereKeyNot($visit->id)->whereNotIn('status', ['approved', 'canceled', 'customer_unavailable'])->exists()) {
                $ticket->update(['status' => 'completed', 'status_reason' => null, 'status_changed_at' => now(), 'status_changed_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
                $handoff = BillingHandoff::query()->firstOrCreate(
                    ['service_ticket_id' => $ticket->id],
                    [
                        'organization_id' => $ticket->organization_id,
                        'visit_id' => $visit->id,
                        'closeout_id' => $closeout->id,
                        'status' => 'ready',
                        'approved_time_minutes' => $this->approvedTimeMinutes($review, $visit),
                        'approved_parts_count' => $this->approvedPartsCount($review, $closeout),
                        'created_by_id' => $actor->id,
                    ],
                );
                if ($handoff->wasRecentlyCreated) {
                    BillingHandoffCreated::dispatch($handoff);
                    $this->audit->record($ticket->organization, $actor, 'billing_handoff.created', $handoff, ['ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id]);
                }
            }

            $this->audit->record($ticket->organization, $actor, 'closeout.approved', $closeout, [
                'visit_id' => $visit->id,
                'outcome' => $closeout->outcome,
                'disposition' => $disposition,
                'adjusted_fields' => collect($review->adjustments)->pluck('type')->unique()->values()->all(),
                'self_review_override' => $selfReview,
            ]);

            return $review->load('adjustments');
        });
    }

    private function authorizeReviewer(Closeout $closeout, User $actor): bool
    {
        $membership = OrganizationMembership::query()->where('organization_id', $closeout->organization_id)->where('user_id', $actor->id)->where('status', 'active')->firstOrFail();
        abort_unless($membership->hasCapability('closeouts.review'), 403);
        if ((int) $closeout->submitted_by_id !== (int) $actor->id) {
            return false;
        }
        $superAdmin = $membership->roles()->where('key', 'super_admin')->exists();
        if (! $superAdmin) {
            $this->audit->record($closeout->visit->serviceTicket->organization, $actor, 'closeout.review_rejected', $closeout, ['reason_code' => 'self_review', 'closeout_id' => $closeout->id]);
            abort(403, 'You cannot review a closeout you submitted.');
        }

        return true;
    }

    private function assertReviewable(Closeout $closeout): void
    {
        if ($closeout->status !== 'submitted' || $closeout->visit->current_closeout_id !== $closeout->id || $closeout->reviews()->exists()) {
            throw ValidationException::withMessages(['review' => 'This closeout version is no longer available for review.']);
        }
    }

    private function validateDisposition(Closeout $closeout, ?string $disposition, ?string $reason): void
    {
        if ($closeout->outcome !== 'customer_unavailable') {
            return;
        }
        if (! in_array($disposition, ['follow_up', 'hold', 'cancel'], true) || blank($reason)) {
            throw ValidationException::withMessages(['disposition' => 'Choose a disposition and provide a reason.']);
        }
    }

    private function applyUnavailableDisposition(Closeout $closeout, User $actor, ?string $disposition, ?string $reason): void
    {
        $visit = $closeout->visit;
        $ticket = $visit->serviceTicket;
        if ($disposition === 'follow_up' && ! $closeout->return_visit_id) {
            $return = Visit::query()->create(['organization_id' => $visit->organization_id, 'service_ticket_id' => $visit->service_ticket_id, 'service_location_id' => $visit->service_location_id, 'return_of_visit_id' => $visit->id, 'status' => 'planned', 'timezone' => $visit->timezone, 'return_reason' => $reason, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
            $closeout->update(['return_visit_id' => $return->id]);
        } elseif ($disposition === 'hold') {
            $ticket->update(['status' => 'on_hold', 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
        } elseif ($disposition === 'cancel') {
            $ticket->update(['status' => 'canceled', 'status_reason' => $reason, 'status_changed_at' => now(), 'status_changed_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
            $ticket->visits()->whereNotIn('status', ['approved', 'canceled', 'customer_unavailable'])->update(['status' => 'canceled', 'canceled_at' => now(), 'canceled_by_id' => $actor->id, 'cancellation_reason' => $reason, 'updated_by_id' => $actor->id]);
        }
    }

    private function storeAdjustments(CloseoutReview $review, Closeout $closeout, array $timeAdjustments, array $partAdjustments): void
    {
        foreach ($timeAdjustments as $entryId => $values) {
            $entry = $closeout->visit->timeEntries()->whereKey($entryId)->whereNotNull('ended_at')->firstOrFail();
            if (blank($values['reason'] ?? null)) {
                throw ValidationException::withMessages(["time_adjustments.$entryId.reason" => 'A reason is required.']);
            }
            $review->adjustments()->create(['organization_id' => $closeout->organization_id, 'type' => 'time', 'visit_time_entry_id' => $entry->id, 'excluded' => (bool) ($values['excluded'] ?? false), 'approved_minutes' => (int) ($values['approved_minutes'] ?? 0), 'reason' => $values['reason']]);
        }
        foreach ($partAdjustments as $partId => $values) {
            $part = $closeout->parts()->whereKey($partId)->whereNull('removed_at')->firstOrFail();
            if (blank($values['reason'] ?? null)) {
                throw ValidationException::withMessages(["part_adjustments.$partId.reason" => 'A reason is required.']);
            }
            $review->adjustments()->create(['organization_id' => $closeout->organization_id, 'type' => 'part', 'visit_part_proposal_id' => $part->id, 'excluded' => (bool) ($values['excluded'] ?? false), 'approved_quantity' => $values['approved_quantity'] ?? $part->quantity, 'approved_unit' => $values['approved_unit'] ?? $part->unit, 'approved_billing_treatment' => $values['approved_billing_treatment'] ?? $part->billing_treatment, 'reason' => $values['reason']]);
        }
    }

    private function approvedTimeMinutes(CloseoutReview $review, Visit $visit): int
    {
        $adjustments = $review->adjustments->where('type', 'time')->keyBy('visit_time_entry_id');

        return (int) $visit->timeEntries->sum(function ($entry) use ($adjustments): int {
            $adjustment = $adjustments->get($entry->id);
            if ($adjustment?->excluded || ! $entry->ended_at) {
                return 0;
            }

            return $adjustment?->approved_minutes ?? (int) ceil($entry->started_at->diffInSeconds($entry->ended_at) / 60);
        });
    }

    private function approvedPartsCount(CloseoutReview $review, Closeout $closeout): int
    {
        $adjustments = $review->adjustments->where('type', 'part')->keyBy('visit_part_proposal_id');

        return $closeout->parts->whereNull('removed_at')->reject(fn ($part) => $adjustments->get($part->id)?->excluded)->count();
    }
}
