<?php

namespace App\Domain\Commercial;

use App\Models\CommercialLeadActivity;
use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeadFollowUpWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function update(
        Organization $organization,
        CommercialLeadIntake $lead,
        User $actor,
        string $engagementStatus,
        ?CarbonInterface $nextFollowUpAt,
        ?string $note,
    ): CommercialLeadIntake {
        return DB::transaction(function () use ($organization, $lead, $actor, $engagementStatus, $nextFollowUpAt, $note): CommercialLeadIntake {
            $lead = CommercialLeadIntake::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($lead->id);

            if ($lead->status !== 'received') {
                throw ValidationException::withMessages([
                    'engagement_status' => 'Follow-up may only be updated for an open lead.',
                ]);
            }

            $fromStatus = $lead->engagement_status ?? 'new';
            $statusChanged = $fromStatus !== $engagementStatus;
            $dateChanged = ! $this->sameInstant($lead->next_follow_up_at, $nextFollowUpAt);
            $note = filled($note) ? trim((string) $note) : null;

            if (! $statusChanged && ! $dateChanged && $note === null) {
                throw ValidationException::withMessages([
                    'follow_up' => 'Change the status or follow-up date, or add a note.',
                ]);
            }

            $changedAt = now();
            $lead->forceFill([
                'engagement_status' => $engagementStatus,
                'next_follow_up_at' => $nextFollowUpAt,
                'engagement_changed_by_id' => $actor->id,
                'engagement_changed_at' => $changedAt,
            ])->save();

            if ($statusChanged) {
                $this->activity($organization, $lead, $actor, 'status_changed', $changedAt, [
                    'from_status' => $fromStatus,
                    'to_status' => $engagementStatus,
                ]);
            }
            if ($dateChanged) {
                $this->activity($organization, $lead, $actor, 'follow_up_changed', $changedAt, [
                    'next_follow_up_at' => $nextFollowUpAt,
                ]);
            }
            if ($note !== null) {
                $this->activity($organization, $lead, $actor, 'note_added', $changedAt, [
                    'note' => $note,
                ]);
            }

            $changedFields = array_values(array_filter([
                $statusChanged ? 'engagement_status' : null,
                $dateChanged ? 'next_follow_up_at' : null,
                $note !== null ? 'note' : null,
            ]));
            $this->audit->record($organization, $actor, 'commercial_lead_intake.follow_up_updated', $lead, [
                'from_status' => $fromStatus,
                'to_status' => $engagementStatus,
                'changed_fields' => $changedFields,
            ]);

            return $lead->refresh();
        });
    }

    private function sameInstant(?CarbonInterface $first, ?CarbonInterface $second): bool
    {
        if ($first === null || $second === null) {
            return $first === null && $second === null;
        }

        return $first->equalTo($second);
    }

    private function activity(
        Organization $organization,
        CommercialLeadIntake $lead,
        User $actor,
        string $eventType,
        CarbonInterface $occurredAt,
        array $attributes = [],
    ): void {
        CommercialLeadActivity::query()->create([
            ...$attributes,
            'organization_id' => $organization->id,
            'commercial_lead_intake_id' => $lead->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
        ]);
    }
}
