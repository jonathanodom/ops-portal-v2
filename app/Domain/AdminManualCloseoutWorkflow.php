<?php

namespace App\Domain;

use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminManualCloseoutWorkflow
{
    private const TARGET_STATUSES = ['planned', 'scheduled', 'assigned', 'en_route', 'on_site'];

    private const OTHER_TERMINAL_STATUSES = ['approved', 'canceled', 'customer_unavailable'];

    public function __construct(
        private readonly FieldExecution $execution,
        private readonly ServiceTicketCompletion $ticketCompletion,
        private readonly AuditRecorder $audit,
    ) {}

    public function canStart(Visit $visit): bool
    {
        return $this->blockers($visit) === [];
    }

    /** @return array<string, string> */
    public function blockers(Visit $visit): array
    {
        $errors = [];
        if (! in_array($visit->serviceTicket->status, ['open', 'on_hold'], true)) {
            $errors['ticket'] = 'Only an open or on-hold Service Ticket can be administratively completed.';
        }
        if (! in_array($visit->status, self::TARGET_STATUSES, true)) {
            $errors['visit'] = 'This Visit is not eligible for administrative completion.';
        }
        if ($visit->serviceTicket->visits()->whereHas('timeEntries', fn ($query) => $query->whereNull('ended_at'))->exists()) {
            $errors['active_timers'] = 'Stop every active timer on this Service Ticket first.';
        }
        if ($visit->serviceTicket->visits()->whereKeyNot($visit->id)->whereNotIn('status', self::OTHER_TERMINAL_STATUSES)->exists()) {
            $errors['other_visits'] = 'Resolve or archive every other unfinished Visit first.';
        }
        $closeout = $visit->currentCloseout;
        if ($closeout && ($closeout->status !== 'draft' || $closeout->reviews()->exists())) {
            $errors['closeout'] = 'This Visit already has a submitted, returned, or reviewed closeout.';
        }

        return $errors;
    }

    public function start(Visit $visit, User $actor): Closeout
    {
        $this->authorize($visit, $actor);
        $this->assertEligible($visit);

        return $this->execution->draft($visit, $actor);
    }

    /** @param array<string, mixed> $data */
    public function save(Visit $visit, User $actor, array $data, int $contentVersion): ?Closeout
    {
        $this->authorize($visit, $actor);
        $this->assertEligible($visit);
        $closeout = $visit->currentCloseout ?: $this->execution->draft($visit, $actor);

        return $this->execution->save($closeout, ['outcome' => 'resolved', ...$data], $contentVersion, $actor);
    }

    /** @param array<string, mixed> $data */
    public function complete(Visit $visit, User $actor, array $data, int $contentVersion, string $reason, string $token): CloseoutReview
    {
        if ($existing = CloseoutReview::query()->where('decision_token', $token)->first()) {
            if (! $existing->administrative_completion || (int) $existing->closeout?->visit_id !== (int) $visit->id) {
                throw ValidationException::withMessages(['completion_token' => 'That completion token has already been used.']);
            }

            return $existing;
        }
        $this->authorize($visit, $actor);

        return DB::transaction(function () use ($visit, $actor, $data, $contentVersion, $reason, $token): CloseoutReview {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $ticket = $visit->serviceTicket()->lockForUpdate()->firstOrFail();
            $this->assertEligible($visit->load('currentCloseout'));
            $closeout = $visit->currentCloseout ?: $this->execution->draft($visit, $actor);
            $saved = $this->execution->save($closeout, ['outcome' => 'resolved', ...$data], $contentVersion, $actor);
            if (! $saved) {
                throw ValidationException::withMessages(['content_version' => 'This closeout changed in another session. Review the latest draft before retrying.']);
            }
            $submitted = $this->execution->submit($visit, $saved, $actor, $token, true);
            $review = CloseoutReview::query()->create([
                'organization_id' => $visit->organization_id,
                'closeout_id' => $submitted->id,
                'reviewer_id' => $actor->id,
                'decision' => 'approved',
                'self_review_override' => true,
                'administrative_completion' => true,
                'administrative_completion_reason' => $reason,
                'administratively_completed_at' => now(),
                'decision_token' => $token,
                'decided_at' => now(),
            ]);
            $visit->update(['status' => 'approved', 'updated_by_id' => $actor->id]);
            $this->ticketCompletion->completeIfEligible($ticket, $actor, $submitted, $review);
            if ($ticket->fresh()->status !== 'completed') {
                throw ValidationException::withMessages(['ticket' => 'The Service Ticket could not be completed because an operational blocker appeared.']);
            }
            $this->audit->record($ticket->organization, $actor, 'closeout.administratively_completed', $submitted, [
                'ticket_id' => $ticket->id,
                'visit_id' => $visit->id,
                'review_id' => $review->id,
                'changed_fields' => array_keys($data),
                'self_review_override' => true,
            ]);

            return $review;
        });
    }

    private function assertEligible(Visit $visit): void
    {
        $errors = $this->blockers($visit);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function authorize(Visit $visit, User $actor): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $visit->organization_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->firstOrFail();
        abort_unless($membership->hasCapability('closeouts.manual_complete'), 403);
    }
}
