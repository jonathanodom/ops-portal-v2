<x-layouts.field :title="$visit->serviceTicket->ticket_number">
    @php
        $closeout = $visit->currentCloseout;
        $contact = $visit->serviceTicket->contact ?? $visit->serviceLocation->primaryContact;
        $activeParts = $closeout?->parts?->whereNull('removed_at') ?? collect();
        $activeMedia = $closeout?->media?->where('state', 'stored') ?? collect();
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
    @endif
    @if (! empty($draftConflict))
        <div class="mb-4 rounded-lg border border-amber-400 bg-amber-50 p-4 text-amber-950" role="alert">
            <p class="font-bold">This shared draft changed before your save completed.</p>
            <p class="mt-1 text-sm">Your submitted values remain in the form below. Review them against the latest saved version, then save again when ready.</p>
        </div>
    @endif
    <x-form-errors />

    <a href="{{ route('field.home') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Today</a>
    <p class="mt-2 text-sm font-bold text-brand-blue">{{ $visit->serviceTicket->ticket_number }}</p>
    <h1 class="text-2xl font-bold">{{ $visit->serviceTicket->title }}</h1>
    <p class="mt-2 text-sm font-semibold text-slate-600">{{ ucfirst(str_replace('_', ' ', $visit->status)) }} · {{ $visit->scheduledStartLocal()?->format('M j, g:i A T') ?? 'Unscheduled' }}</p>

    @can('execute', $visit)
        @if ($visit->status === 'assigned')
            <form method="POST" action="{{ route('field.visits.transition', $visit) }}" class="mt-5">
                @csrf
                <input type="hidden" name="status" value="en_route">
                <button class="button-action w-full">Start En Route</button>
            </form>
        @endif
        @if ($visit->status === 'en_route')
            <form method="POST" action="{{ route('field.visits.transition', $visit) }}" class="mt-5">
                @csrf
                <input type="hidden" name="status" value="on_site">
                <button class="button-action w-full">Mark On Site</button>
            </form>
        @endif
    @endcan

    <section class="surface mt-5 p-5">
        <h2 class="font-bold">Customer & location</h2>
        <p class="mt-3 font-bold">{{ $visit->serviceTicket->customer->display_name }}</p>
        <p>{{ $visit->serviceLocation->name }}<br>{{ $visit->serviceLocation->formattedAddress() }}</p>
        @if ($contact)
            <h3 class="mt-4 text-sm font-bold text-slate-700">Customer contact</h3>
            <p class="font-semibold">{{ $contact->name }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @if ($contact->phone)<a class="button-secondary" href="tel:{{ $contact->phone }}">Call</a>@endif
                @if ($contact->email)<a class="button-secondary" href="mailto:{{ $contact->email }}">Email</a>@endif
            </div>
        @endif
        @if ($visit->serviceLocation->access_instructions)
            <h3 class="mt-4 text-sm font-bold text-brand-orange">Access instructions</h3>
            <p class="whitespace-pre-line">{{ $visit->serviceLocation->access_instructions }}</p>
        @endif
    </section>

    <section class="surface mt-4 p-5">
        <h2 class="font-bold">Work scope</h2>
        <p class="mt-3 whitespace-pre-line">{{ $visit->serviceTicket->description }}</p>
        @if ($visit->serviceTicket->customer_visible_summary)
            <h3 class="mt-4 text-sm font-bold text-slate-700">Customer-visible summary</h3>
            <p class="whitespace-pre-line">{{ $visit->serviceTicket->customer_visible_summary }}</p>
        @endif
    </section>

    <section class="surface mt-4 p-5">
        <h2 class="font-bold">Crew</h2>
        @foreach ($visit->assignments as $assignment)
            <p class="mt-2 text-sm"><span class="font-semibold">{{ $assignment->membership->user->name }}</span>{{ $assignment->is_lead ? ' · Lead' : '' }}</p>
        @endforeach
    </section>

    @can('execute', $visit)
        <section class="surface mt-4 p-5">
            <h2 class="text-lg font-bold">Time</h2>
            @forelse ($closeout?->timeEntries ?? collect() as $entry)
                <div class="mt-3 border-t border-slate-200 pt-3 text-sm">
                    <p>{{ $entry->user->name }} · {{ ucfirst($entry->category) }} · {{ $entry->started_at->format('g:i A') }}–{{ $entry->ended_at?->format('g:i A') ?? 'running' }}</p>
                    @if ($closeout?->status === 'draft' && $entry->user_id === auth()->id() && $entry->ended_at)
                        <details class="mt-2">
                            <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correct my entry</summary>
                            <form method="POST" action="{{ route('field.visits.time.update', [$visit, $entry]) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <label class="form-label" for="started_at_{{ $entry->id }}">Started</label>
                                <input class="form-input" id="started_at_{{ $entry->id }}" name="started_at" type="datetime-local" value="{{ $entry->started_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="ended_at_{{ $entry->id }}">Ended</label>
                                <input class="form-input" id="ended_at_{{ $entry->id }}" name="ended_at" type="datetime-local" value="{{ $entry->ended_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="correction_reason_{{ $entry->id }}">Correction reason</label>
                                <textarea class="form-textarea" id="correction_reason_{{ $entry->id }}" name="correction_reason" required></textarea>
                                <button class="button-secondary w-full">Save correction</button>
                            </form>
                        </details>
                    @endif
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-500">No time captured.</p>
            @endforelse
            @if (! $closeout || $closeout->status === 'draft')
                <form method="POST" action="{{ route('field.visits.timer', $visit) }}" class="mt-4 grid grid-cols-2 gap-2">
                    @csrf
                    <select class="form-input col-span-2" name="category" aria-label="Time category">
                        <option value="travel">Travel</option><option value="on_site">On site</option><option value="other">Other</option>
                    </select>
                    <button class="button-secondary" name="action" value="start">Start</button>
                    <button class="button-secondary" name="action" value="stop">Stop</button>
                </form>
            @endif
        </section>

        <section class="surface mt-4 p-5">
            <h2 class="text-lg font-bold">Closeout</h2>
            @if (! $closeout || $closeout->status === 'draft')
                <form method="POST" action="{{ route('field.visits.draft', $visit) }}" class="mt-4 space-y-4" data-dirty-form>
                    @csrf
                    <input type="hidden" name="content_version" value="{{ $closeout?->content_version ?? 1 }}">
                    <label class="form-label" for="outcome">Outcome</label>
                    <select class="form-input" id="outcome" name="outcome">
                        <option value="">Choose outcome</option>
                        @foreach (['resolved' => 'Resolved', 'needs_return_trip' => 'Needs return trip', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('outcome', $closeout?->outcome) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @foreach (['diagnosis' => 'Diagnosis', 'work_performed' => 'Work performed', 'exceptions' => 'Exceptions', 'recommendations' => 'Recommendations', 'return_reason' => 'Return reason', 'unfinished_work' => 'Unfinished work', 'needed_equipment' => 'Needed parts / equipment', 'hold_reason' => 'Hold reason', 'unavailable_detail' => 'Unavailable detail'] as $field => $label)
                        <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                        <textarea class="form-textarea" id="{{ $field }}" name="{{ $field }}">{{ old($field, $closeout?->$field) }}</textarea>
                    @endforeach
                    <label class="form-label" for="unavailable_category">Unavailable category</label>
                    <select class="form-input" id="unavailable_category" name="unavailable_category"><option value="">Choose category</option>@foreach (config('field_execution.unavailable_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('unavailable_category', $closeout?->unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                    <label class="form-label" for="representative_name">Representative name</label>
                    <input class="form-input" id="representative_name" name="representative_name" value="{{ old('representative_name', $closeout?->representative_name) }}">
                    <label class="form-label" for="ack_unavailable_category">Acknowledgment fallback</label>
                    <select class="form-input" id="ack_unavailable_category" name="ack_unavailable_category"><option value="">Choose fallback</option>@foreach (config('field_execution.ack_fallbacks') as $value => $label)<option value="{{ $value }}" @selected(old('ack_unavailable_category', $closeout?->ack_unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                    <label class="form-label" for="ack_unavailable_detail">Acknowledgment fallback detail</label>
                    <input class="form-input" id="ack_unavailable_detail" name="ack_unavailable_detail" value="{{ old('ack_unavailable_detail', $closeout?->ack_unavailable_detail) }}">
                    <label class="form-label" for="no_photo_category">No-photo category</label>
                    <select class="form-input" id="no_photo_category" name="no_photo_category"><option value="">Choose category</option>@foreach (config('field_execution.no_photo_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('no_photo_category', $closeout?->no_photo_category) === $value)>{{ $label }}</option>@endforeach</select>
                    <label class="form-label" for="no_photo_detail">No-photo detail</label>
                    <input class="form-input" id="no_photo_detail" name="no_photo_detail" value="{{ old('no_photo_detail', $closeout?->no_photo_detail) }}">
                    <button class="button-primary w-full">Save draft</button>
                </form>
                @if ($closeout?->lastSavedBy)
                    <p class="mt-3 text-xs text-slate-500">Last saved by {{ $closeout->lastSavedBy->name }} {{ $closeout->updated_at->diffForHumans() }}.</p>
                @endif
            @else
                <p class="mt-3 font-semibold text-emerald-800">Submitted {{ $closeout->submitted_at?->format('M j, g:i A') }}</p>
            @endif
        </section>

        @if (! $closeout || $closeout->status === 'draft')
            <section class="surface mt-4 p-5">
                <h2 class="font-bold">Private photos</h2>
                <form action="{{ route('field.visits.media.store', $visit) }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-3" data-upload-form>
                    @csrf
                    <label class="form-label" for="photo_category">Photo category</label>
                    <select class="form-input" id="photo_category" name="category">@foreach (config('field_execution.photo_categories') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <label class="form-label" for="photo">Photo</label>
                    <input class="form-input" id="photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" capture="environment" required>
                    <label class="form-label" for="caption">Caption</label>
                    <input class="form-input" id="caption" name="caption">
                    <progress class="w-full" value="0" max="100" hidden></progress>
                    <p data-upload-status class="text-sm" role="status"></p>
                    <button class="button-secondary w-full">Upload photo</button>
                </form>
                @forelse ($activeMedia as $media)
                    <div class="mt-3 flex min-h-11 items-center justify-between gap-3 border-t border-slate-200 pt-3">
                        <a class="font-bold text-brand-blue" href="{{ route('field.media.show', $media) }}">{{ ucfirst(str_replace('_', ' ', $media->category)) }}</a>
                        <form method="POST" action="{{ route('field.visits.media.remove', [$visit, $media]) }}">@csrf @method('DELETE')<button class="button-secondary">Remove</button></form>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No photos uploaded.</p>
                @endforelse
            </section>

            <section class="surface mt-4 p-5">
                <h2 class="font-bold">Parts / equipment</h2>
                @foreach ($activeParts as $part)
                    <div class="mt-3 flex min-h-11 items-center justify-between gap-3 border-t border-slate-200 pt-3">
                        <p><span class="font-semibold">{{ $part->description }}</span><br><span class="text-sm text-slate-600">{{ $part->quantity }} {{ $part->unit }} · {{ ucfirst(str_replace('_', ' ', $part->billing_treatment)) }}</span></p>
                        <form method="POST" action="{{ route('field.visits.parts.remove', [$visit, $part]) }}">@csrf @method('DELETE')<button class="button-secondary">Remove</button></form>
                    </div>
                @endforeach
                <form method="POST" action="{{ route('field.visits.parts.store', $visit) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="form-label" for="part_description">Description</label><input class="form-input" id="part_description" name="description" required>
                    <label class="form-label" for="quantity">Quantity</label><input class="form-input" id="quantity" type="number" step=".01" name="quantity" required>
                    <label class="form-label" for="unit">Unit</label><input class="form-input" id="unit" name="unit">
                    <label class="form-label" for="serial_mac">Serial / MAC</label><input class="form-input" id="serial_mac" name="serial_mac">
                    <label class="form-label" for="billing_treatment">Billing treatment</label><select class="form-input" id="billing_treatment" name="billing_treatment">@foreach (config('field_execution.billing_treatments') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <label class="form-label" for="technician_note">Technician note</label><textarea class="form-textarea" id="technician_note" name="technician_note"></textarea>
                    <button class="button-secondary w-full">Add proposal</button>
                </form>
            </section>

            <section class="sticky bottom-24 mt-4 rounded-xl border border-orange-300 bg-white p-4">
                <form method="POST" action="{{ route('field.visits.submit', $visit) }}">
                    @csrf
                    <input type="hidden" name="submission_token" value="{{ Str::uuid() }}">
                    <label class="flex min-h-11 gap-3"><input type="checkbox" name="acknowledgment_confirmed" value="1"><span class="text-sm font-semibold">I confirm the work and outcome were reviewed with the representative.</span></label>
                    <button class="button-action mt-3 w-full">Submit closeout</button>
                </form>
            </section>
        @endif
    @endcan

    <section class="surface mt-4 p-5">
        <h2 class="font-bold">Ticket visit history</h2>
        @foreach ($visit->serviceTicket->visits as $history)
            <p class="mt-2 text-sm">Visit #{{ $history->id }} · {{ ucfirst(str_replace('_', ' ', $history->status)) }}</p>
        @endforeach
    </section>
</x-layouts.field>
