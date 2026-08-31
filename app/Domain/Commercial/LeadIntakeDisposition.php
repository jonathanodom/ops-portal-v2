<?php

namespace App\Domain\Commercial;

use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeadIntakeDisposition
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function markSpam(Organization $organization, CommercialLeadIntake $lead, User $actor): CommercialLeadIntake
    {
        return $this->transition($organization, $lead, $actor, 'spam');
    }

    public function archive(Organization $organization, CommercialLeadIntake $lead, User $actor): CommercialLeadIntake
    {
        return $this->transition($organization, $lead, $actor, 'archived');
    }

    public function reopen(Organization $organization, CommercialLeadIntake $lead, User $actor): CommercialLeadIntake
    {
        return $this->transition($organization, $lead, $actor, 'received');
    }

    private function transition(
        Organization $organization,
        CommercialLeadIntake $lead,
        User $actor,
        string $targetStatus,
    ): CommercialLeadIntake {
        return DB::transaction(function () use ($organization, $lead, $actor, $targetStatus): CommercialLeadIntake {
            $lead = CommercialLeadIntake::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($lead->id);

            $allowed = match ($targetStatus) {
                'spam', 'archived' => $lead->status === 'received',
                'received' => in_array($lead->status, ['spam', 'archived'], true),
                default => false,
            };

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'lead_intake' => 'This lead intake cannot make the requested status change.',
                ]);
            }

            $fromStatus = $lead->status;
            $lead->update(['status' => $targetStatus]);

            $event = match ($targetStatus) {
                'spam' => 'commercial_lead_intake.marked_spam',
                'archived' => 'commercial_lead_intake.archived',
                default => 'commercial_lead_intake.reopened',
            };
            $this->audit->record($organization, $actor, $event, $lead, [
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
            ]);

            return $lead->refresh();
        });
    }
}
