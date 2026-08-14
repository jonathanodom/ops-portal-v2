<?php

namespace App\Domain;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Support\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VisitScheduler
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array<int, int>  $membershipIds
     * @return Collection<int, Visit>
     */
    public function conflicts(Visit $visit, array $membershipIds, CarbonInterface $start, CarbonInterface $end): Collection
    {
        return Visit::query()
            ->forOrganization($visit->organization_id)
            ->whereKeyNot($visit->id)
            ->where('status', '!=', 'canceled')
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->whereHas('assignments', fn ($query) => $query->whereIn('organization_membership_id', $membershipIds))
            ->with('serviceTicket')
            ->orderBy('scheduled_start_at')
            ->get();
    }

    /**
     * @param  array{start: CarbonInterface, end: CarbonInterface}|null  $window
     * @param  array<int, int>  $membershipIds
     */
    public function save(
        Visit $visit,
        ?array $window,
        array $membershipIds,
        ?int $leadMembershipId,
        User $actor,
        bool $confirmConflicts = false,
    ): Visit {
        $membershipIds = array_values(array_unique(array_map('intval', $membershipIds)));
        $leadAssignmentMode = match (count($membershipIds)) {
            0 => 'none',
            1 => 'automatic',
            default => 'explicit',
        };
        $leadMembershipId = match (count($membershipIds)) {
            0 => null,
            1 => $membershipIds[0],
            default => $leadMembershipId,
        };

        if ($window === null && $membershipIds !== []) {
            throw ValidationException::withMessages(['assignees' => 'Schedule the visit before assigning a crew.']);
        }
        if (count($membershipIds) > 1 && (! $leadMembershipId || ! in_array($leadMembershipId, $membershipIds, true))) {
            throw ValidationException::withMessages(['lead_membership_id' => 'Choose one assigned user as the lead.']);
        }
        $this->assertSchedulable($visit);

        $memberships = OrganizationMembership::query()
            ->with(['roles.capabilities', 'capabilityOverrides'])
            ->where('organization_id', $visit->organization_id)
            ->where('status', 'active')
            ->whereIn('id', $membershipIds)
            ->get();
        if ($memberships->count() !== count($membershipIds)
            || $memberships->contains(fn (OrganizationMembership $membership) => ! $membership->hasCapability('experience.field.access'))) {
            throw ValidationException::withMessages(['assignees' => 'Every assignee must be an active field-capable member of this organization.']);
        }

        $conflicts = $window ? $this->conflicts($visit, $membershipIds, $window['start'], $window['end']) : collect();
        if ($conflicts->isNotEmpty() && ! $confirmConflicts) {
            throw ValidationException::withMessages([
                'schedule_conflict' => 'This crew overlaps '.$conflicts->pluck('serviceTicket.ticket_number')->join(', ').'. Review the warning and confirm to save anyway.',
            ]);
        }

        return DB::transaction(function () use (
            $visit, $window, $membershipIds, $leadMembershipId, $leadAssignmentMode, $actor, $confirmConflicts
        ): Visit {
            $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
            $this->assertSchedulable($visit);
            if ($membershipIds !== []) {
                OrganizationMembership::query()->whereIn('id', $membershipIds)->lockForUpdate()->get();
            }
            $lockedConflicts = $window ? $this->conflicts($visit, $membershipIds, $window['start'], $window['end']) : collect();
            if ($lockedConflicts->isNotEmpty() && ! $confirmConflicts) {
                throw ValidationException::withMessages([
                    'schedule_conflict' => 'The crew schedule changed. Review the overlap warning and confirm to save anyway.',
                ]);
            }
            $from = $visit->status;
            $to = $window === null ? 'planned' : ($membershipIds === [] ? 'scheduled' : 'assigned');
            $visit->update([
                'scheduled_start_at' => $window['start'] ?? null,
                'scheduled_end_at' => $window['end'] ?? null,
                'scheduled_by_id' => $window ? $actor->id : null,
                'status' => $to,
                'updated_by_id' => $actor->id,
            ]);
            $visit->assignments()->delete();
            foreach ($membershipIds as $membershipId) {
                VisitAssignment::query()->create([
                    'organization_id' => $visit->organization_id,
                    'visit_id' => $visit->id,
                    'organization_membership_id' => $membershipId,
                    'is_lead' => $membershipId === $leadMembershipId,
                    'assigned_by_id' => $actor->id,
                ]);
            }
            $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.scheduled', $visit, [
                'from' => $from,
                'to' => $to,
                'ticket_id' => $visit->service_ticket_id,
                'assignment_ids' => $membershipIds,
                'lead_membership_id' => $leadMembershipId,
                'lead_assignment_mode' => $leadAssignmentMode,
                'changed_fields' => ['scheduled_start_at', 'scheduled_end_at', 'assignments'],
            ]);
            if ($confirmConflicts && $lockedConflicts->isNotEmpty()) {
                $this->audit->record($visit->serviceTicket->organization, $actor, 'visit.schedule_conflict_overridden', $visit, [
                    'conflicting_visit_ids' => $lockedConflicts->pluck('id')->values()->all(),
                ]);
            }

            return $visit->refresh();
        });
    }

    private function assertSchedulable(Visit $visit): void
    {
        $visit->loadMissing('serviceTicket');
        if (! in_array($visit->serviceTicket->status, ['open', 'on_hold'], true)) {
            throw ValidationException::withMessages(['status' => 'Reopen this Service Ticket before scheduling callback work.']);
        }
        if (! in_array($visit->status, ['planned', 'scheduled', 'assigned'], true)) {
            throw ValidationException::withMessages(['status' => 'Only pre-execution Visits can be rescheduled or reassigned.']);
        }
    }
}
