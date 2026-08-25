<?php

namespace App\Domain;

use App\Models\OrganizationMembership;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\VisitTimeAllocationSet;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VisitTimeAllocationWorkflow
{
    public const CAPABILITY = 'visit_time.allocate_work';

    public function __construct(private readonly AuditRecorder $audit, private readonly ServiceTicketWorkItemWorkflow $workItems) {}

    /** @param array<int, array{work_item_id:?int,allocated_seconds:int}> $rows */
    public function allocate(VisitTimeEntry $timeEntry, User $actor, array $rows, string $reason): VisitTimeAllocationSet
    {
        return DB::transaction(function () use ($timeEntry, $actor, $rows, $reason): VisitTimeAllocationSet {
            $entry = VisitTimeEntry::query()->lockForUpdate()->with(['visit.serviceTicket', 'closeout'])->findOrFail($timeEntry->id);
            $membership = OrganizationMembership::query()->where('organization_id', $entry->organization_id)
                ->where('user_id', $actor->id)->where('status', 'active')
                ->with(['roles.capabilities', 'capabilityOverrides'])->lockForUpdate()->first();
            abort_unless($membership && $membership->roles->contains('key', 'super_admin') && $membership->hasCapability(self::CAPABILITY), 403);
            abort_unless((int) $entry->visit->organization_id === (int) $entry->organization_id
                && (int) $entry->visit->serviceTicket->organization_id === (int) $entry->organization_id, 403);
            if ($entry->active_user_id || ! $entry->ended_at) {
                throw ValidationException::withMessages(['allocation' => 'Stop the timer before allocating work time.']);
            }
            if (! in_array($entry->category, ['on_site', 'other'], true)) {
                throw ValidationException::withMessages(['allocation' => 'Travel time cannot be allocated to Work Items.']);
            }
            if (blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'An allocation reason is required.']);
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['allocations' => 'Enter at least one allocation.']);
            }
            $keys = collect($rows)->map(fn ($row) => $row['work_item_id'] === null ? 'primary' : 'item:'.$row['work_item_id']);
            if ($keys->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['allocations' => 'Each allocation target may appear only once.']);
            }
            $items = ServiceTicketWorkItem::query()->whereIn('id', collect($rows)->pluck('work_item_id')->filter())->lockForUpdate()->get()->keyBy('id');
            foreach ($rows as $row) {
                if ((int) $row['allocated_seconds'] <= 0) {
                    throw ValidationException::withMessages(['allocations' => 'Every allocation must be greater than zero.']);
                }
                if ($row['work_item_id'] !== null) {
                    $item = $items->get((int) $row['work_item_id']);
                    abort_unless($item && (int) $item->organization_id === (int) $entry->organization_id
                        && (int) $item->service_ticket_id === (int) $entry->visit->service_ticket_id, 404);
                    if ($entry->closeout->status !== 'draft' && $item->status === 'open') {
                        throw ValidationException::withMessages(['allocations' => 'Disposition open Work Items before allocating submitted Visit time.']);
                    }
                }
            }
            $total = (int) collect($rows)->sum('allocated_seconds');
            if ($total > $entry->effectiveDurationSeconds()) {
                throw ValidationException::withMessages(['allocations' => 'Allocated time cannot exceed the factual duration.']);
            }
            $sequence = ((int) $entry->allocationSets()->max('sequence')) + 1;
            $set = $entry->allocationSets()->create(['organization_id' => $entry->organization_id, 'sequence' => $sequence,
                'reason' => $reason, 'allocated_by_id' => $actor->id]);
            foreach (array_values($rows) as $position => $row) {
                $set->allocations()->create(['organization_id' => $entry->organization_id,
                    'service_ticket_work_item_id' => $row['work_item_id'], 'allocated_seconds' => $row['allocated_seconds'], 'position' => $position]);
                if ($row['work_item_id'] !== null) {
                    $this->workItems->touch($items->get((int) $row['work_item_id']), $entry->visit, $actor);
                }
            }
            $this->audit->record($entry->visit->serviceTicket->organization, $actor, 'visit_time.allocation_created', $entry, [
                'visit_id' => $entry->visit_id, 'entry_id' => $entry->id, 'sequence' => $sequence,
                'work_item_ids' => $items->keys()->map(fn ($id) => (int) $id)->values()->all(),
                'allocated_seconds' => $total, 'unallocated_seconds' => $entry->effectiveDurationSeconds() - $total,
            ]);

            return $set->load(['allocations.workItem', 'allocatedBy']);
        });
    }
}
