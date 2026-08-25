<?php

namespace App\Domain;

use App\Models\ServiceTicket;
use App\Models\VisitTimeEntry;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class WorkItemTimeAttribution
{
    /** @return array{duration_seconds:int, allocated_seconds:int, unallocated_seconds:int, rows:Collection<int, array{work_item_id:?int,label:string,seconds:int}>, sequence:?int} */
    public function forEntry(VisitTimeEntry $entry): array
    {
        $duration = $entry->category === 'travel' ? 0 : $entry->effectiveDurationSeconds();
        $set = $entry->allocationSets->sortByDesc('sequence')->first();
        if (! $set) {
            return [
                'duration_seconds' => $duration,
                'allocated_seconds' => $duration,
                'unallocated_seconds' => 0,
                'rows' => collect([[
                    'work_item_id' => $entry->service_ticket_work_item_id,
                    'label' => $entry->workItem?->title ?? 'Primary Ticket scope',
                    'seconds' => $duration,
                ]]),
                'sequence' => null,
            ];
        }
        $rows = $set->allocations->map(fn ($allocation): array => [
            'work_item_id' => $allocation->service_ticket_work_item_id,
            'label' => $allocation->workItem?->title ?? 'Primary Ticket scope',
            'seconds' => (int) $allocation->allocated_seconds,
        ])->values();
        $allocated = (int) $rows->sum('seconds');

        return ['duration_seconds' => $duration, 'allocated_seconds' => $allocated,
            'unallocated_seconds' => max(0, $duration - $allocated), 'rows' => $rows, 'sequence' => (int) $set->sequence];
    }

    /** @return Collection<int, array{work_item_id:?int,label:string,seconds:int}> */
    public function forTicket(ServiceTicket $ticket): Collection
    {
        return $ticket->visits->flatMap->timeEntries
            ->filter(fn (VisitTimeEntry $entry): bool => in_array($entry->category, ['on_site', 'other'], true))
            ->flatMap(function (VisitTimeEntry $entry): Collection {
                $projection = $this->forEntry($entry);
                $rows = $projection['rows'];
                if ($projection['unallocated_seconds'] > 0) {
                    $rows = $rows->push(['work_item_id' => -1, 'label' => 'Unallocated', 'seconds' => $projection['unallocated_seconds']]);
                }

                return $rows;
            })->groupBy('work_item_id')->map(fn (Collection $rows): array => [
                'work_item_id' => $rows->first()['work_item_id'], 'label' => $rows->first()['label'], 'seconds' => (int) $rows->sum('seconds'),
            ])->values();
    }

    public function assertFits(VisitTimeEntry $entry, int $proposedDurationSeconds): void
    {
        $set = $entry->allocationSets()->with('allocations')->orderByDesc('sequence')->first();
        if ($set && (int) $set->allocations->sum('allocated_seconds') > $proposedDurationSeconds) {
            throw ValidationException::withMessages([
                'time' => 'Reduce the current time allocation before shortening this factual interval.',
            ]);
        }
    }
}
