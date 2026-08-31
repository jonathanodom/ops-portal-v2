<section id="field-v2-panel-time" role="tabpanel" aria-labelledby="field-v2-tab-time" tabindex="0" data-v2-panel="time" class="space-y-3">
    @if($activeTimer)<div class="rounded-lg border-2 border-orange-300 bg-orange-50 p-4"><p class="text-xs font-bold uppercase tracking-wide text-brand-orange">Active timer</p><p class="mt-1 text-xl font-bold tabular-nums" data-v2-live-timer data-started-at="{{ $activeTimer->effective_started_at->toIso8601String() }}">Running</p><p class="mt-1 text-sm">{{ Str::headline($activeTimer->category) }} · {{ $activeTimer->workItem?->title ?? 'Primary Ticket scope' }}</p></div>@endif
    <div class="surface p-5">
        <h2 class="text-lg font-bold">Captured time</h2>
        <div class="mt-3 space-y-3">
            @forelse($closeout?->timeEntries ?? collect() as $entry)
                    @php($currentAllocation = $entry->allocationSets->sortByDesc('sequence')->first())
                    @php($correctionForm = 'field-correction-'.$entry->id)
                <article class="rounded-lg border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2"><p class="font-bold">{{ $entry->user->name }} · {{ Str::headline($entry->category) }}</p>@if($entry->effective_ended_at)<span class="status-active">{{ gmdate('G\h i\m', $entry->effectiveDurationSeconds()) }}</span>@else<span class="status-priority">Running</span>@endif</div>
                    <p class="mt-2"><x-local-time :value="$entry->effective_started_at" :timezone="$visit->timezone" format="M j, g:i A T" />–@if($entry->effective_ended_at)<x-local-time :value="$entry->effective_ended_at" :timezone="$visit->timezone" format="g:i A T" />@else now @endif</p>
                    <p class="mt-1 text-slate-600"><strong>Captured focus:</strong> {{ $entry->workItem?->title ?? 'Primary Ticket scope' }}</p>
                    @if($entry->hasSubmittedCorrection())<p class="mt-2"><span class="status-active">Corrected by office</span></p>@endif
                    @if($currentAllocation)<p class="mt-2 text-slate-600"><strong>Current allocation:</strong> {{ $currentAllocation->allocations->map(fn ($row) => ($row->workItem?->title ?? 'Primary Ticket scope').' · '.number_format($row->allocated_seconds / 60, 1).' min')->join('; ') }}</p>@endif
                    @if($closeout?->status === 'draft' && $entry->user_id === auth()->id() && $entry->ended_at)
                        <details class="mt-2" @if(old('time_form') === $correctionForm) open @endif>
                            <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correct my entry</summary>
                            <form method="POST" action="{{ route('field.visits.time.update', [$visit, $entry]) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <input type="hidden" name="time_form" value="{{ $correctionForm }}">
                                <label class="form-label" for="v2_started_{{ $entry->id }}">Started</label>
                                <input class="form-input" id="v2_started_{{ $entry->id }}" name="started_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('started_at') : $entry->started_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="v2_ended_{{ $entry->id }}">Ended</label>
                                <input class="form-input" id="v2_ended_{{ $entry->id }}" name="ended_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('ended_at') : $entry->ended_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="v2_reason_{{ $entry->id }}">Correction reason</label>
                                <textarea class="form-textarea" id="v2_reason_{{ $entry->id }}" name="correction_reason" required>{{ old('time_form') === $correctionForm ? old('correction_reason') : '' }}</textarea>
                                <button class="button-secondary w-full">Save correction</button>
                            </form>
                        </details>
                    @endif
                </article>
            @empty<p class="text-sm text-slate-500">No time captured.</p>@endforelse
        </div>
    </div>
    @can('execute', $visit)
        @if($workspaceWritable)
            <div class="surface p-5"><h2 class="font-bold">Timer controls</h2>@if($activeTimer?->category === 'on_site')<p class="mt-2 text-sm text-slate-600">Switch Work focus under the Work tab.</p>@endif<form method="POST" action="{{ route('field.visits.timer', $visit) }}" class="mt-4 grid grid-cols-2 gap-2">@csrf<select class="form-input col-span-2" name="category" aria-label="Time category"><option value="travel">Travel</option><option value="on_site">On site</option><option value="other">Other</option></select><select class="form-input col-span-2" name="work_item_id" aria-label="Work focus"><option value="">Primary Ticket scope</option>@foreach($visit->serviceTicket->workItems->whereIn('status', ['open', 'needs_follow_up']) as $item)<option value="{{ $item->id }}">{{ $item->title }}</option>@endforeach</select><button class="button-secondary" name="action" value="start">Start</button><button class="button-secondary" name="action" value="stop">Stop</button></form></div>
        @endif
    @endcan
</section>
