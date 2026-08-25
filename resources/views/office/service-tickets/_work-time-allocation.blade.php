@php
    $canAllocateWork = $activeMembership->roles->contains('key', 'super_admin') && $activeMembership->hasCapability('visit_time.allocate_work');
    $projection = app(\App\Domain\WorkItemTimeAttribution::class)->forEntry($entry);
    $allocationForm = 'allocation-'.$entry->id.'-'.$context;
    $allocationItems = $visit->serviceTicket->workItems;
@endphp
<div class="mt-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
    <p><strong>Captured focus:</strong> {{ $entry->workItem?->title ?? 'Primary Ticket scope' }}</p>
    <p class="mt-1"><strong>Current allocation:</strong> {{ $projection['rows']->map(fn ($row) => $row['label'].' · '.number_format($row['seconds']/60, 2).' min')->join('; ') }}</p>
    @if($projection['unallocated_seconds'] > 0)<p class="mt-1 font-semibold text-brand-orange">Unallocated: {{ number_format($projection['unallocated_seconds']/60, 2) }} min</p>@endif
    @if($canAllocateWork && $entry->ended_at && in_array($entry->category, ['on_site','other'], true))
        <details class="mt-2"><summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Allocate work time</summary>
            <form method="POST" action="{{ route('office.visit-time-entries.allocations.store', $entry) }}" class="space-y-3" data-modal-form>
                @csrf<input type="hidden" name="context" value="{{ $context }}"><input type="hidden" name="review_closeout_id" value="{{ $reviewCloseoutId }}"><input type="hidden" name="allocation_form" value="{{ $allocationForm }}">
                <p class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-950">Allocation changes where recorded labor is attributed. It does not change the factual clock, approved minutes, Billing Handoff, or Invoice.</p>
                <p class="font-semibold">Recorded total: {{ number_format($entry->effectiveDurationSeconds()/60, 2) }} minutes</p>
                @foreach(collect([null])->merge($allocationItems->pluck('id')) as $position => $targetId)
                    @php($existingSeconds = (int) ($projection['rows']->firstWhere('work_item_id', $targetId)['seconds'] ?? 0))
                    <div><input type="hidden" name="allocations[{{ $position }}][target]" value="{{ $targetId ? 'item:'.$targetId : 'primary' }}"><label class="form-label" for="allocation_{{ $entry->id }}_{{ $position }}">{{ $targetId ? $allocationItems->firstWhere('id',$targetId)?->title : 'Primary Ticket scope' }} · minutes</label><input class="form-input" id="allocation_{{ $entry->id }}_{{ $position }}" name="allocations[{{ $position }}][minutes]" inputmode="decimal" value="{{ number_format($existingSeconds/60, 2, '.', '') }}" required></div>
                @endforeach
                <label class="form-label" for="allocation_reason_{{ $entry->id }}_{{ $context }}">Allocation reason</label><textarea class="form-textarea" id="allocation_reason_{{ $entry->id }}_{{ $context }}" name="reason" required></textarea>
                <button class="button-secondary w-full">Save immutable allocation</button>
            </form>
        </details>
    @endif
    @if($entry->allocationSets->isNotEmpty())<details class="mt-2"><summary class="min-h-11 cursor-pointer py-3 font-semibold">Allocation history</summary><div class="space-y-3">@foreach($entry->allocationSets->sortByDesc('sequence') as $set)<article class="border-l-4 {{ $loop->first ? 'border-brand-blue' : 'border-slate-300' }} pl-3"><p class="font-bold">Sequence {{ $set->sequence }}{{ $loop->first ? ' · Current allocation' : '' }}</p><p class="text-xs text-slate-500">{{ $set->allocatedBy?->name }} · <x-local-time :value="$set->created_at" :timezone="$activeOrganization->timezone" /></p><p class="mt-1">{{ $set->allocations->map(fn ($row) => ($row->workItem?->title ?? 'Primary Ticket scope').' · '.number_format($row->allocated_seconds/60,2).' min')->join('; ') }}</p><p class="mt-1 text-slate-600">{{ $set->reason }}</p></article>@endforeach</div></details>@endif
</div>
