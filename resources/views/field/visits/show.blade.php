<x-layouts.field :title="$visit->serviceTicket->ticket_number">
    @php
        $closeout = $visit->currentCloseout;
        $contact = $visit->serviceTicket->contact ?? $visit->serviceLocation->primaryContact;
        $activeParts = $closeout?->parts?->whereNull('removed_at') ?? collect();
        $activeMedia = $closeout?->media?->where('state', 'stored') ?? collect();
        $inheritedMedia = ($versions ?? collect())->where('id','!=',$closeout?->id)->flatMap->media->where('state','stored');
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

    @if($visit->status === 'returned_for_correction' && $closeout?->parent)
        @php($returnReview = $closeout->parent->reviews->firstWhere('decision','returned'))
        <div class="mb-4 rounded-lg border border-orange-300 bg-orange-50 p-4 text-orange-950" role="alert">
            <p class="font-bold">Returned for correction · Version {{ $closeout->version }}</p>
            <p class="mt-1 text-sm">{{ $returnReview?->reason ?: 'Review the closeout and resubmit the corrected version.' }}</p>
            <p class="mt-2 text-xs font-semibold">Original acknowledgment, time, and prior photos remain preserved as read-only evidence.</p>
        </div>
    @endif

    <a href="{{ route('field.home') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Today</a>
    <p class="mt-2 text-sm font-bold text-brand-blue">{{ $visit->serviceTicket->ticket_number }}</p>
    <h1 class="text-2xl font-bold">{{ $visit->serviceTicket->title }}</h1>
    <p class="mt-2 text-sm font-semibold text-slate-600">{{ ucfirst(str_replace('_', ' ', $visit->status)) }} · {{ $visit->scheduledStartLocal()?->format('M j, g:i A T') ?? 'Unscheduled' }}</p>

    @if($activeMembership->hasCapability('invoices.present') && $activeMembership->hasCapability('payments.collect') && ($collectInvoice=$visit->serviceTicket->invoices->first()))
        <a class="button-primary mt-4 w-full" href="{{ route('invoices.present',$collectInvoice) }}">Open invoice / collect payment</a>
    @endif

    @if($visit->status === 'canceled')
        <div class="mt-4 rounded-lg border border-slate-300 bg-slate-100 p-4" role="status"><p class="font-bold">This visit was canceled.</p><p class="mt-1 text-sm text-slate-700">Execution and closeout are read-only. You may correct your completed time with a reason.</p></div>
    @endif

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
                    <p>{{ $entry->user->name }} · {{ ucfirst($entry->category) }} · <x-local-time :value="$entry->started_at" :timezone="$visit->timezone" format="g:i A T" />–@if($entry->ended_at)<x-local-time :value="$entry->ended_at" :timezone="$visit->timezone" format="g:i A T" />@else running @endif</p>
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
            @if ($visit->status !== 'canceled' && (! $closeout || $closeout->status === 'draft'))
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

        @if($visit->status !== 'canceled')
        <section class="surface mt-4 p-5">
            <h2 class="text-lg font-bold">Closeout</h2>
            @if (! $closeout || $closeout->status === 'draft')
                <form method="POST" action="{{ route('field.visits.draft', $visit) }}" class="mt-4" data-dirty-form>
                    @csrf
                    <input type="hidden" name="content_version" value="{{ $closeout?->content_version ?? 1 }}">

                    <fieldset class="space-y-4">
                        <legend class="text-base font-bold text-slate-900">Visit outcome</legend>
                        <p class="text-sm text-slate-600">Choose the result that best describes this visit.</p>
                        <label class="form-label" for="outcome">Outcome</label>
                        <select class="form-input @error('outcome') border-red-500 bg-red-50 @enderror" id="outcome" name="outcome" @error('outcome') aria-invalid="true" aria-describedby="outcome-error" @enderror>
                            <option value="">Choose outcome</option>
                            @foreach (['resolved' => 'Resolved', 'needs_return_trip' => 'Needs return trip', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('outcome', $closeout?->outcome) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <x-field-error field="outcome" />
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Work summary</legend>
                        @foreach (['diagnosis' => 'Diagnosis', 'work_performed' => 'Work performed', 'exceptions' => 'Exceptions', 'recommendations' => 'Recommendations'] as $field => $label)
                            <div>
                                <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                <textarea class="form-textarea mt-1 @error($field) border-red-500 bg-red-50 @enderror" id="{{ $field }}" name="{{ $field }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>{{ old($field, $closeout?->$field) }}</textarea>
                                <x-field-error :field="$field" />
                            </div>
                        @endforeach
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Return trip or hold details</legend>
                        <p class="text-sm text-slate-600">Complete the fields that apply when another visit is needed or work is placed on hold.</p>
                        @foreach (['return_reason' => 'Return reason', 'unfinished_work' => 'Unfinished work', 'needed_equipment' => 'Needed parts / equipment', 'hold_reason' => 'Hold reason'] as $field => $label)
                            <div>
                                <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                <textarea class="form-textarea mt-1 @error($field) border-red-500 bg-red-50 @enderror" id="{{ $field }}" name="{{ $field }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>{{ old($field, $closeout?->$field) }}</textarea>
                                <x-field-error :field="$field" />
                            </div>
                        @endforeach
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Customer unavailable</legend>
                        <p class="text-sm text-slate-600">Complete this section only when the selected outcome is Customer unavailable.</p>
                        <div>
                            <label class="form-label" for="unavailable_category">Reason category</label>
                            <select class="form-input mt-1 @error('unavailable_category') border-red-500 bg-red-50 @enderror" id="unavailable_category" name="unavailable_category" @error('unavailable_category') aria-invalid="true" aria-describedby="unavailable_category-error" @enderror><option value="">Choose a reason</option>@foreach (config('field_execution.unavailable_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('unavailable_category', $closeout?->unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                            <x-field-error field="unavailable_category" />
                        </div>
                        <div>
                            <label class="form-label" for="unavailable_detail">Details</label>
                            <textarea class="form-textarea mt-1 @error('unavailable_detail') border-red-500 bg-red-50 @enderror" id="unavailable_detail" name="unavailable_detail" @error('unavailable_detail') aria-invalid="true" aria-describedby="unavailable_detail-error" @enderror>{{ old('unavailable_detail', $closeout?->unavailable_detail) }}</textarea>
                            <x-field-error field="unavailable_detail" />
                        </div>
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Customer acknowledgment</legend>
                        <p class="text-sm text-slate-600">Enter the person who reviewed the work. If no one could acknowledge it, leave the name blank and complete the fallback section below.</p>
                        <div>
                            <label class="form-label" for="representative_name">Customer or point-of-contact name</label>
                            <input class="form-input mt-1 @error('representative_name') border-red-500 bg-red-50 @enderror" id="representative_name" name="representative_name" autocomplete="name" value="{{ old('representative_name', $closeout?->representative_name) }}" @error('representative_name') aria-invalid="true" aria-describedby="representative_name-error" @enderror>
                            <x-field-error field="representative_name" />
                        </div>
                        <div class="space-y-4 border-t border-slate-200 pt-4">
                            <p class="font-semibold text-slate-900">Couldn’t obtain acknowledgment?</p>
                            <div>
                                <label class="form-label" for="ack_unavailable_category">Reason</label>
                                <select class="form-input mt-1 @error('ack_unavailable_category') border-red-500 bg-red-50 @enderror" id="ack_unavailable_category" name="ack_unavailable_category" @error('ack_unavailable_category') aria-invalid="true" aria-describedby="ack_unavailable_category-error" @enderror><option value="">Choose a reason</option>@foreach (config('field_execution.ack_fallbacks') as $value => $label)<option value="{{ $value }}" @selected(old('ack_unavailable_category', $closeout?->ack_unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                                <x-field-error field="ack_unavailable_category" />
                            </div>
                            <div>
                                <label class="form-label" for="ack_unavailable_detail">Details</label>
                                <textarea class="form-textarea mt-1 @error('ack_unavailable_detail') border-red-500 bg-red-50 @enderror" id="ack_unavailable_detail" name="ack_unavailable_detail" @error('ack_unavailable_detail') aria-invalid="true" aria-describedby="ack_unavailable_detail-error" @enderror>{{ old('ack_unavailable_detail', $closeout?->ack_unavailable_detail) }}</textarea>
                                <x-field-error field="ack_unavailable_detail" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">No-photo fallback</legend>
                        <p class="text-sm text-slate-600">Complete this section only when required photo evidence cannot be provided.</p>
                        <div>
                            <label class="form-label" for="no_photo_category">Reason</label>
                            <select class="form-input mt-1 @error('no_photo_category') border-red-500 bg-red-50 @enderror" id="no_photo_category" name="no_photo_category" @error('no_photo_category') aria-invalid="true" aria-describedby="no_photo_category-error" @enderror><option value="">Choose a reason</option>@foreach (config('field_execution.no_photo_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('no_photo_category', $closeout?->no_photo_category) === $value)>{{ $label }}</option>@endforeach</select>
                            <x-field-error field="no_photo_category" />
                        </div>
                        <div>
                            <label class="form-label" for="no_photo_detail">Details</label>
                            <textarea class="form-textarea mt-1 @error('no_photo_detail') border-red-500 bg-red-50 @enderror" id="no_photo_detail" name="no_photo_detail" @error('no_photo_detail') aria-invalid="true" aria-describedby="no_photo_detail-error" @enderror>{{ old('no_photo_detail', $closeout?->no_photo_detail) }}</textarea>
                            <x-field-error field="no_photo_detail" />
                        </div>
                    </fieldset>

                    <button class="button-primary mt-6 w-full">Save draft</button>
                </form>
                @if ($closeout?->lastSavedBy)
                    <p class="mt-3 text-xs text-slate-500">Last saved by {{ $closeout->lastSavedBy->name }} · <x-local-time :value="$closeout->updated_at" :timezone="$visit->timezone" />.</p>
                @endif
            @else
                <p class="mt-3 font-semibold text-emerald-800">Submitted <x-local-time :value="$closeout->submitted_at" :timezone="$visit->timezone" /></p>
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
                @if($inheritedMedia->isNotEmpty())
                    <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3"><p class="text-sm font-bold">Inherited read-only evidence</p><div class="mt-2 flex flex-wrap gap-2">@foreach($inheritedMedia as $media)<a class="button-secondary" href="{{ route('field.media.show',$media) }}">{{ ucfirst(str_replace('_',' ',$media->category)) }}</a>@endforeach</div></div>
                @endif
            </section>

            <section class="surface mt-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 class="font-bold">Catalog items / parts &amp; equipment</h2><p class="mt-1 text-sm text-slate-600">Select a standard Catalog item or record a custom proposal.</p></div>
                    @if($activeMembership->hasCapability('catalog.use'))
                        <x-catalog-picker
                            :id="'field-catalog-'.$visit->id"
                            :action="route('field.visits.catalog-items.store', $visit)"
                            :services="$catalogServices ?? collect()"
                            :products="$catalogProducts ?? collect()"
                            :packages="$catalogPackages ?? collect()"
                            :field-mode="true"
                        />
                    @endif
                </div>
                @foreach ($activeParts as $part)
                    <div class="mt-3 flex min-h-11 items-center justify-between gap-3 border-t border-slate-200 pt-3">
                        <p><span class="font-semibold">{{ $part->catalog_name_snapshot ?: $part->description }}</span>@if($part->catalog_item_type)<span class="ml-2 status-active">{{ ucfirst($part->catalog_item_type) }}</span>@endif<br><span class="text-sm text-slate-600">{{ $part->catalog_quantity_millis ? rtrim(rtrim(number_format($part->catalog_quantity_millis / 1000, 3, '.', ''), '0'), '.') : $part->quantity }} {{ $part->unit }} · {{ ucfirst(str_replace('_', ' ', $part->billing_treatment)) }}</span></p>
                        <form method="POST" action="{{ route('field.visits.parts.remove', [$visit, $part]) }}">@csrf @method('DELETE')<button class="button-secondary">Remove</button></form>
                    </div>
                @endforeach
                <form method="POST" action="{{ route('field.visits.parts.store', $visit) }}" class="mt-4 space-y-3">
                    @csrf
                    <h3 class="font-bold">Add custom proposal</h3>
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
                    <label class="flex min-h-11 gap-3 rounded-lg @error('acknowledgment_confirmed') border border-red-500 bg-red-50 p-3 @enderror">
                        <input type="checkbox" name="acknowledgment_confirmed" value="1" @error('acknowledgment_confirmed') aria-invalid="true" aria-describedby="acknowledgment_confirmed-error" @enderror>
                        <span class="text-sm font-semibold">I confirm the work and outcome were reviewed with the customer or point of contact named above.</span>
                    </label>
                    <x-field-error field="acknowledgment_confirmed" />
                    <button class="button-action mt-3 w-full">Submit closeout</button>
                </form>
            </section>
        @endif
        @endif
    @endcan

    <section class="surface mt-4 p-5">
        <h2 class="font-bold">Ticket visit history</h2>
        @foreach ($visit->serviceTicket->visits as $history)
            <p class="mt-2 text-sm">Visit #{{ $history->id }} · {{ ucfirst(str_replace('_', ' ', $history->status)) }}</p>
        @endforeach
    </section>
    @if(($versions ?? collect())->count() > 1)
        <section class="surface mt-4 p-5">
            <h2 class="font-bold">Closeout version history</h2>
            <div class="mt-3 space-y-3">
                @foreach($versions as $version)
                    <div class="rounded-lg border border-slate-200 p-3">
                        <p class="font-semibold">Version {{ $version->version }} · {{ ucfirst($version->status) }}</p>
                        @foreach($version->reviews as $review)<p class="mt-1 text-sm text-slate-600">{{ ucfirst($review->decision) }} by {{ $review->reviewer?->name ?? 'Office reviewer' }}</p>@endforeach
                        <p class="mt-1 text-xs text-slate-500">{{ $version->media->where('state','stored')->count() }} preserved photo(s) · {{ $version->parts->whereNull('removed_at')->count() }} proposal(s)</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</x-layouts.field>
