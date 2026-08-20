@php
    $activeTimer = $visit->timeEntries->first(fn ($entry) => $entry->active_user_id === auth()->id());
    $canManageCrewTime = $activeMembership->hasCapability('visits.execute_any');
    $timeOwners = $visit->assignments->pluck('membership.user')->push(auth()->user())->unique('id')->sortBy('name');
    $timeSeconds = $visit->timeEntries->sum(fn ($entry) => $entry->ended_at ? $entry->started_at->diffInSeconds($entry->ended_at) : 0);
@endphp
<dialog id="execution-visit-{{ $visit->id }}" data-execution-dialog data-visit-id="{{ $visit->id }}" aria-labelledby="execution-title-{{ $visit->id }}" class="m-0 h-dvh w-dvw max-h-none max-w-none bg-canvas p-0 text-ink sm:m-auto sm:h-[92dvh] sm:w-[96vw] sm:rounded-xl sm:border sm:border-slate-300">
    <div class="flex h-full min-h-0 flex-col">
        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white p-4 sm:p-5">
            <div><p class="text-sm font-bold text-brand-blue">{{ $ticket->ticket_number }} · {{ $visit->displayLabel() }}</p><h2 id="execution-title-{{ $visit->id }}" class="mt-1 text-xl font-bold text-slate-950">Execution workspace</h2><p class="mt-1 text-sm text-slate-600">{{ ucfirst(str_replace('_', ' ', $visit->status)) }} · {{ $visit->scheduledStartLocal()?->format('M j, Y g:i A T') ?? 'Unscheduled' }}</p></div>
            <button type="button" class="button-secondary min-w-11 px-3" data-dialog-close aria-label="Close execution workspace">Close</button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto p-4 pb-[calc(2rem+env(safe-area-inset-bottom))] sm:p-6">
            <div data-dialog-status tabindex="-1">@if(request()->integer('execution_visit') === $visit->id)@if(session('status'))<div class="mb-4 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif<x-form-errors />@endif</div>
            @if($visit->status === 'canceled')<div class="mb-5 rounded-lg border border-slate-300 bg-slate-100 p-4" role="status"><p class="font-bold">Canceled visit · execution locked</p><p class="mt-1 text-sm text-slate-700">Completed time remains available for an authorized reasoned correction.</p></div>@endif
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(320px,.8fr)]">
                <div class="space-y-5">
                    <section class="surface p-5"><h3 class="font-bold">Customer and visit</h3><p class="mt-3 font-semibold">{{ $ticket->customer->display_name }} · {{ $ticket->serviceLocation->name }}</p><p class="mt-1 text-sm text-slate-600">{{ $ticket->serviceLocation->formattedAddress() }}</p><p class="mt-3 text-sm"><strong>Timezone:</strong> {{ $visit->timezone }}</p><p class="mt-2 text-sm"><strong>Crew:</strong> {{ $visit->assignments->map(fn ($assignment) => ($assignment->is_lead ? 'Lead: ' : '').$assignment->membership->user->name)->join(', ') ?: 'No crew assigned' }}</p></section>
                    @if($visit->status !== 'canceled')
                        <section class="surface p-5"><h3 class="font-bold">Visit status</h3>
                            @if($visit->status === 'assigned')<form method="POST" action="{{ route('office.visits.execution.transition', $visit) }}" class="mt-4" data-modal-form>@csrf<input type="hidden" name="status" value="en_route"><button class="button-action w-full">Start En Route</button></form>
                            @elseif($visit->status === 'en_route')<form method="POST" action="{{ route('office.visits.execution.transition', $visit) }}" class="mt-4" data-modal-form>@csrf<input type="hidden" name="status" value="on_site"><button class="button-action w-full">Mark On Site</button></form>
                            @else<p class="mt-3 text-sm text-slate-600">No execution transition is available at the current status.</p>@endif
                        </section>
                    @endif
                    <section class="surface p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-bold">Time entries</h3><span class="text-sm font-bold">Total {{ number_format($timeSeconds / 3600, 2) }} hours</span></div>
                        @forelse($visit->timeEntries->sortBy('started_at') as $entry)
                            <article class="mt-4 border-t border-slate-200 pt-4 text-sm"><p class="font-semibold">{{ $entry->user->name }} · {{ ucfirst(str_replace('_', ' ', $entry->category)) }}</p><p class="mt-1 text-slate-600"><x-local-time :value="$entry->started_at" :timezone="$visit->timezone" format="M j, g:i A T" /> – @if($entry->ended_at)<x-local-time :value="$entry->ended_at" :timezone="$visit->timezone" format="M j, g:i A T" />@else<span class="font-bold text-brand-orange">Running</span>@endif</p>
                                @if($entry->ended_at && $entry->closeout?->status === 'draft' && ($entry->user_id === auth()->id() || $canManageCrewTime))
                                    @php($correctionForm = 'correction-'.$entry->id)
                                    <details class="mt-2" @if($errors->has('time') && old('time_form') === $correctionForm) open @endif><summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correct entry</summary><form method="POST" action="{{ route('office.visits.execution.time.update', [$visit, $entry]) }}" class="space-y-3" data-modal-form>@csrf @method('PUT')<input type="hidden" name="time_form" value="{{ $correctionForm }}"><label class="form-label" for="office_started_{{ $entry->id }}">Started</label><input class="form-input" id="office_started_{{ $entry->id }}" name="started_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('started_at') : $entry->started_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required><label class="form-label" for="office_ended_{{ $entry->id }}">Ended</label><input class="form-input" id="office_ended_{{ $entry->id }}" name="ended_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('ended_at') : $entry->ended_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required><label class="form-label" for="office_reason_{{ $entry->id }}">Correction reason</label><textarea class="form-textarea" id="office_reason_{{ $entry->id }}" name="correction_reason" required>{{ old('time_form') === $correctionForm ? old('correction_reason') : '' }}</textarea><button class="button-secondary w-full">Save correction</button></form></details>
                                @endif
                            </article>
                        @empty<p class="mt-3 text-sm text-slate-500">No time captured.</p>@endforelse
                    </section>
                </div>
                <aside class="space-y-5">
                    @if($visit->status !== 'canceled')
                        <section class="surface p-5"><h3 class="font-bold">My timer</h3>
                            @if($activeTimer)<p class="mt-2 text-sm font-semibold text-brand-orange">{{ ucfirst(str_replace('_', ' ', $activeTimer->category)) }} running since <x-local-time :value="$activeTimer->started_at" :timezone="$visit->timezone" format="g:i A T" /></p><form method="POST" action="{{ route('office.visits.execution.timer', $visit) }}" class="mt-4" data-modal-form>@csrf<input type="hidden" name="action" value="stop"><button class="button-secondary w-full">Stop timer</button></form>
                            @elseif(in_array($visit->status, ['en_route', 'on_site'], true))<form method="POST" action="{{ route('office.visits.execution.timer', $visit) }}" class="mt-4 space-y-3" data-modal-form>@csrf<input type="hidden" name="action" value="start"><label class="form-label" for="timer_category_{{ $visit->id }}">Category</label><select class="form-input" id="timer_category_{{ $visit->id }}" name="category">@if($visit->status === 'en_route')<option value="travel">Travel</option>@endif @if($visit->status === 'on_site')<option value="on_site">On site</option><option value="travel">Travel</option>@endif<option value="other">Other</option></select><button class="button-secondary w-full">Start timer</button></form>
                            @else<p class="mt-2 text-sm text-slate-500">Timers become available after En Route.</p>@endif
                        </section>
                    @endif
                    @if($canManageCrewTime && $visit->status !== 'canceled' && (!$visit->currentCloseout || $visit->currentCloseout->status === 'draft'))
                        @php($manualForm = 'manual-'.$visit->id)
                        <section class="surface p-5"><h3 class="font-bold">Add completed crew time</h3><form method="POST" action="{{ route('office.visits.execution.time.store', $visit) }}" class="mt-4 space-y-3" data-modal-form>@csrf<input type="hidden" name="time_form" value="{{ $manualForm }}"><label class="form-label" for="time_owner_{{ $visit->id }}">Time owner</label><select class="form-input" id="time_owner_{{ $visit->id }}" name="user_id">@foreach($timeOwners as $owner)<option value="{{ $owner->id }}" @selected(old('time_form') === $manualForm && (int) old('user_id') === $owner->id)>{{ $owner->name }}</option>@endforeach</select><label class="form-label" for="manual_category_{{ $visit->id }}">Category</label><select class="form-input" id="manual_category_{{ $visit->id }}" name="category">@foreach(['travel' => 'Travel', 'on_site' => 'On site', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('time_form') === $manualForm && old('category') === $value)>{{ $label }}</option>@endforeach</select><label class="form-label" for="manual_started_{{ $visit->id }}">Started · {{ $visit->timezone }}</label><input class="form-input" id="manual_started_{{ $visit->id }}" name="started_at" type="datetime-local" value="{{ old('time_form') === $manualForm ? old('started_at') : '' }}" required><label class="form-label" for="manual_ended_{{ $visit->id }}">Ended · {{ $visit->timezone }}</label><input class="form-input" id="manual_ended_{{ $visit->id }}" name="ended_at" type="datetime-local" value="{{ old('time_form') === $manualForm ? old('ended_at') : '' }}" required><label class="form-label" for="manual_reason_{{ $visit->id }}">Reason</label><textarea class="form-textarea" id="manual_reason_{{ $visit->id }}" name="correction_reason" required>{{ old('time_form') === $manualForm ? old('correction_reason') : '' }}</textarea><button class="button-secondary w-full">Add time entry</button></form></section>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</dialog>
