<x-layouts.field :title="$visit->serviceTicket->ticket_number">
    @php
        $closeout = $visit->currentCloseout;
        $selectedOutcome = old('outcome', $closeout?->outcome);
        $outcomeLabels = ['resolved' => 'Resolved', 'needs_return_trip' => 'Needs return trip', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'];
        $contact = $visit->serviceTicket->contact ?? $visit->serviceLocation->primaryContact;
        $activeParts = $closeout?->parts?->whereNull('removed_at') ?? collect();
        $activeMedia = $closeout?->media?->where('state', 'stored') ?? collect();
        $inheritedMedia = ($versions ?? collect())->where('id','!=',$closeout?->id)->flatMap->media->where('state','stored');
        $closeoutMissing = collect($closeoutReadinessErrors ?? ['outcome' => 'Choose an outcome.']);
        $closeoutFieldError = fn (string $field) => $errors->first($field) ?: $closeoutMissing->get($field);
        $showCloseoutAction = in_array($visit->status, ['on_site', 'returned_for_correction'], true) && (! $closeout || $closeout->status === 'draft');
        $canSubmitCloseout = $activeMembership->hasCapability('visits.execute_any') || $visit->assignments->contains(fn ($assignment) => $assignment->membership->user_id === auth()->id() && $assignment->is_lead);
    @endphp

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-emerald-400 bg-emerald-50 p-4 text-emerald-950" role="status" data-save-feedback>
            <p class="font-bold">Saved successfully</p>
            <p class="mt-1 text-sm font-semibold">{{ session('status') }}</p>
        </div>
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
            <form method="POST" action="{{ route('field.visits.transition', $visit) }}" class="sticky top-20 z-10 mt-5 rounded-xl border border-orange-300 bg-white p-2">
                @csrf
                <input type="hidden" name="status" value="en_route">
                <button class="button-action w-full">Start En Route</button>
            </form>
        @endif
        @if ($visit->status === 'en_route')
            <form method="POST" action="{{ route('field.visits.transition', $visit) }}" class="sticky top-20 z-10 mt-5 rounded-xl border border-orange-300 bg-white p-2">
                @csrf
                <input type="hidden" name="status" value="on_site">
                <button class="button-action w-full">Mark On Site</button>
            </form>
        @endif
    @endcan

    @can('execute', $visit)
        <nav class="field-section-nav mt-4 flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1" aria-label="Visit workspace sections">
            <a class="field-section-link" href="#visit-time">Time</a>
            @if($visit->status !== 'canceled')<a class="field-section-link" href="#visit-closeout">Notes &amp; outcome</a>@endif
            @if($visit->status !== 'canceled' && (! $closeout || $closeout->status === 'draft'))
                <a class="field-section-link" href="#visit-photos">Photos</a>
                <a class="field-section-link" href="#visit-parts">Parts</a>
            @endif
        </nav>
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
        <section id="visit-time" class="surface mt-4 scroll-mt-24 p-5">
            <h2 class="text-lg font-bold">Time</h2>
            @forelse ($closeout?->timeEntries ?? collect() as $entry)
                <div class="mt-3 border-t border-slate-200 pt-3 text-sm">
                    <p>{{ $entry->user->name }} · {{ ucfirst($entry->category) }} · <x-local-time :value="$entry->started_at" :timezone="$visit->timezone" format="g:i A T" />–@if($entry->ended_at)<x-local-time :value="$entry->ended_at" :timezone="$visit->timezone" format="g:i A T" />@else running @endif</p>
                    @if ($closeout?->status === 'draft' && $entry->user_id === auth()->id() && $entry->ended_at)
                        @php($correctionForm = 'field-correction-'.$entry->id)
                        <details class="mt-2" @if($errors->has('time') && old('time_form') === $correctionForm) open @endif>
                            <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correct my entry</summary>
                            <form method="POST" action="{{ route('field.visits.time.update', [$visit, $entry]) }}" class="space-y-3">
                                @csrf @method('PUT')
                                <input type="hidden" name="time_form" value="{{ $correctionForm }}">
                                <label class="form-label" for="started_at_{{ $entry->id }}">Started</label>
                                <input class="form-input" id="started_at_{{ $entry->id }}" name="started_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('started_at') : $entry->started_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="ended_at_{{ $entry->id }}">Ended</label>
                                <input class="form-input" id="ended_at_{{ $entry->id }}" name="ended_at" type="datetime-local" value="{{ old('time_form') === $correctionForm ? old('ended_at') : $entry->ended_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
                                <label class="form-label" for="correction_reason_{{ $entry->id }}">Correction reason</label>
                                <textarea class="form-textarea" id="correction_reason_{{ $entry->id }}" name="correction_reason" required>{{ old('time_form') === $correctionForm ? old('correction_reason') : '' }}</textarea>
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
        <section id="visit-closeout" class="surface mt-4 scroll-mt-24 p-5">
            <h2 class="text-lg font-bold">Closeout</h2>
            @if (! $closeout || $closeout->status === 'draft')
                <form method="POST" action="{{ route('field.visits.draft', $visit) }}" class="mt-4" data-dirty-form>
                    @csrf
                    <input type="hidden" name="content_version" value="{{ $closeout?->content_version ?? 1 }}">

                    <fieldset class="space-y-4" data-outcome-selector data-closeout-field="outcome">
                        <legend class="text-base font-bold text-slate-900">Visit outcome</legend>
                        <p class="text-sm text-slate-600">Choose the result that best describes this visit.</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 {{ $closeoutFieldError('outcome') ? 'rounded-lg border border-red-500 bg-red-50 p-2' : '' }}">
                            @foreach ($outcomeLabels as $value => $label)
                                <label class="field-outcome-option" data-outcome="{{ $value }}">
                                    <input class="sr-only" type="radio" name="outcome" value="{{ $value }}" data-outcome-label="{{ $label }}" @checked($selectedOutcome === $value) @if($closeoutFieldError('outcome')) aria-invalid="true" aria-describedby="outcome-error" @endif>
                                    <span class="font-bold text-slate-950">{{ $label }}</span>
                                    <span class="mt-1 text-xs text-slate-600">{{ match($value) {'resolved' => 'Work is complete.', 'needs_return_trip' => 'More work must be scheduled.', 'customer_unavailable' => 'Work could not be completed with the customer.', default => 'Pause this ticket for office follow-up.'} }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-field-error field="outcome" :message="$closeoutMissing->get('outcome')" />
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Work summary</legend>
                        @foreach (['diagnosis' => 'Diagnosis', 'work_performed' => 'Work performed', 'exceptions' => 'Exceptions', 'recommendations' => 'Recommendations'] as $field => $label)
                            <div data-closeout-field="{{ $field }}">
                                <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                <textarea class="form-textarea mt-1 {{ $closeoutFieldError($field) ? 'border-red-500 bg-red-50' : '' }}" id="{{ $field }}" name="{{ $field }}" @if($closeoutFieldError($field)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>{{ old($field, $closeout?->$field) }}</textarea>
                                <x-field-error :field="$field" :message="$closeoutMissing->get($field)" />
                            </div>
                        @endforeach
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Return trip or hold details</legend>
                        <p class="text-sm text-slate-600">Complete the fields that apply when another visit is needed or work is placed on hold.</p>
                        @foreach (['return_reason' => 'Return reason', 'unfinished_work' => 'Unfinished work', 'needed_equipment' => 'Needed parts / equipment', 'hold_reason' => 'Hold reason'] as $field => $label)
                            <div data-closeout-field="{{ $field }}">
                                <label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                <textarea class="form-textarea mt-1 {{ $closeoutFieldError($field) ? 'border-red-500 bg-red-50' : '' }}" id="{{ $field }}" name="{{ $field }}" @if($closeoutFieldError($field)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>{{ old($field, $closeout?->$field) }}</textarea>
                                <x-field-error :field="$field" :message="$closeoutMissing->get($field)" />
                            </div>
                        @endforeach
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Customer unavailable</legend>
                        <p class="text-sm text-slate-600">Complete this section only when the selected outcome is Customer unavailable.</p>
                        <div data-closeout-field="unavailable_category">
                            <label class="form-label" for="unavailable_category">Reason category</label>
                            <select class="form-input mt-1 {{ $closeoutFieldError('unavailable_category') ? 'border-red-500 bg-red-50' : '' }}" id="unavailable_category" name="unavailable_category" @if($closeoutFieldError('unavailable_category')) aria-invalid="true" aria-describedby="unavailable_category-error" @endif><option value="">Choose a reason</option>@foreach (config('field_execution.unavailable_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('unavailable_category', $closeout?->unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                            <x-field-error field="unavailable_category" :message="$closeoutMissing->get('unavailable_category')" />
                        </div>
                        <div data-closeout-field="unavailable_detail">
                            <label class="form-label" for="unavailable_detail">Details</label>
                            <textarea class="form-textarea mt-1 {{ $closeoutFieldError('unavailable_detail') ? 'border-red-500 bg-red-50' : '' }}" id="unavailable_detail" name="unavailable_detail" @if($closeoutFieldError('unavailable_detail')) aria-invalid="true" aria-describedby="unavailable_detail-error" @endif>{{ old('unavailable_detail', $closeout?->unavailable_detail) }}</textarea>
                            <x-field-error field="unavailable_detail" :message="$closeoutMissing->get('unavailable_detail')" />
                        </div>
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">Customer acknowledgment</legend>
                        <p class="text-sm text-slate-600">Enter the person who reviewed the work. If no one could acknowledge it, leave the name blank and complete the fallback section below.</p>
                        <div data-closeout-field="representative_name">
                            <label class="form-label" for="representative_name">Customer or point-of-contact name</label>
                            <input class="form-input mt-1 {{ $closeoutFieldError('representative_name') ? 'border-red-500 bg-red-50' : '' }}" id="representative_name" name="representative_name" autocomplete="name" value="{{ old('representative_name', $closeout?->representative_name) }}" @if($closeoutFieldError('representative_name')) aria-invalid="true" aria-describedby="representative_name-error" @endif>
                            <x-field-error field="representative_name" :message="$closeoutMissing->get('representative_name')" />
                        </div>
                        <div class="space-y-4 border-t border-slate-200 pt-4">
                            <p class="font-semibold text-slate-900">Couldn’t obtain acknowledgment?</p>
                            <div data-closeout-field="ack_unavailable_category">
                                <label class="form-label" for="ack_unavailable_category">Reason</label>
                                <select class="form-input mt-1 {{ $closeoutFieldError('ack_unavailable_category') ? 'border-red-500 bg-red-50' : '' }}" id="ack_unavailable_category" name="ack_unavailable_category" @if($closeoutFieldError('ack_unavailable_category')) aria-invalid="true" aria-describedby="ack_unavailable_category-error" @endif><option value="">Choose a reason</option>@foreach (config('field_execution.ack_fallbacks') as $value => $label)<option value="{{ $value }}" @selected(old('ack_unavailable_category', $closeout?->ack_unavailable_category) === $value)>{{ $label }}</option>@endforeach</select>
                                <x-field-error field="ack_unavailable_category" :message="$closeoutMissing->get('ack_unavailable_category')" />
                            </div>
                            <div data-closeout-field="ack_unavailable_detail">
                                <label class="form-label" for="ack_unavailable_detail">Details</label>
                                <textarea class="form-textarea mt-1 {{ $closeoutFieldError('ack_unavailable_detail') ? 'border-red-500 bg-red-50' : '' }}" id="ack_unavailable_detail" name="ack_unavailable_detail" @if($closeoutFieldError('ack_unavailable_detail')) aria-invalid="true" aria-describedby="ack_unavailable_detail-error" @endif>{{ old('ack_unavailable_detail', $closeout?->ack_unavailable_detail) }}</textarea>
                                <x-field-error field="ack_unavailable_detail" :message="$closeoutMissing->get('ack_unavailable_detail')" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="mt-6 space-y-4 border-t border-slate-200 pt-6">
                        <legend class="px-1 text-base font-bold text-slate-900">No-photo fallback</legend>
                        <p class="text-sm text-slate-600">Complete this section only when required photo evidence cannot be provided.</p>
                        <div data-closeout-field="no_photo_category">
                            <label class="form-label" for="no_photo_category">Reason</label>
                            <select class="form-input mt-1 {{ $closeoutFieldError('no_photo_category') ? 'border-red-500 bg-red-50' : '' }}" id="no_photo_category" name="no_photo_category" @if($closeoutFieldError('no_photo_category')) aria-invalid="true" aria-describedby="no_photo_category-error" @endif><option value="">Choose a reason</option>@foreach (config('field_execution.no_photo_reasons') as $value => $label)<option value="{{ $value }}" @selected(old('no_photo_category', $closeout?->no_photo_category) === $value)>{{ $label }}</option>@endforeach</select>
                            <x-field-error field="no_photo_category" :message="$closeoutMissing->get('no_photo_category')" />
                        </div>
                        <div data-closeout-field="no_photo_detail">
                            <label class="form-label" for="no_photo_detail">Details</label>
                            <textarea class="form-textarea mt-1 {{ $closeoutFieldError('no_photo_detail') ? 'border-red-500 bg-red-50' : '' }}" id="no_photo_detail" name="no_photo_detail" @if($closeoutFieldError('no_photo_detail')) aria-invalid="true" aria-describedby="no_photo_detail-error" @endif>{{ old('no_photo_detail', $closeout?->no_photo_detail) }}</textarea>
                            <x-field-error field="no_photo_detail" :message="$closeoutMissing->get('no_photo_detail')" />
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
            <section id="visit-photos" class="surface mt-4 scroll-mt-24 p-5">
                <h2 class="font-bold">Private photos</h2>
                <form action="{{ route('field.visits.media.store', $visit) }}" method="POST" enctype="multipart/form-data" class="mt-4 space-y-3" data-upload-form>
                    @csrf
                    <label class="form-label" for="photo_category">Photo category</label>
                    <select class="form-input" id="photo_category" name="category">@foreach (config('field_execution.photo_categories') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    <fieldset class="space-y-3">
                        <legend class="form-label">Photo source</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="form-label" for="photo_camera">Take photo</label>
                                <input class="form-input min-h-11" id="photo_camera" type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" capture="environment" data-upload-photo-source data-source-label="Camera">
                            </div>
                            <div>
                                <label class="form-label" for="photo_library">Choose from gallery or files</label>
                                <input class="form-input min-h-11" id="photo_library" type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" data-upload-photo-source data-source-label="Gallery or files">
                            </div>
                        </div>
                        <p class="text-sm text-slate-600" data-upload-selection role="status" aria-live="polite">No photo selected.</p>
                    </fieldset>
                    <label class="form-label" for="caption">Caption</label>
                    <input class="form-input" id="caption" name="caption">
                    <progress class="w-full" value="0" max="100" hidden></progress>
                    <p data-upload-status class="text-sm" role="status"></p>
                    <button class="button-secondary w-full" data-upload-submit>Upload photo</button>
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

            <section id="visit-parts" class="surface mt-4 scroll-mt-24 p-5">
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

            @if($showCloseoutAction)
                <div class="field-closeout-action" data-closeout-action-footer>
                    <div class="mx-auto flex min-h-16 max-w-2xl items-center justify-between gap-3 px-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-950">{{ $closeoutMissing->isEmpty() ? (($outcomeLabels[$selectedOutcome] ?? 'Closeout').' - Ready to submit') : 'Closeout - '.($closeoutMissing->count() === 1 ? '1 item missing' : $closeoutMissing->count().' items missing') }}</p>
                            <p class="truncate text-xs text-slate-600">{{ $closeoutMissing->isEmpty() ? 'Review the final acknowledgment.' : 'Review what is still required.' }}</p>
                        </div>
                        <button type="button" class="{{ $closeoutMissing->isEmpty() && $canSubmitCloseout ? 'button-action' : 'button-secondary' }} shrink-0" data-closeout-dialog-open>{{ $closeoutMissing->isEmpty() && $canSubmitCloseout ? 'Submit' : 'Review' }}</button>
                    </div>
                </div>

                <dialog id="field-closeout-review-dialog" class="field-closeout-dialog" data-closeout-dialog data-auto-open="{{ $errors->has('acknowledgment_confirmed') ? 'true' : 'false' }}" aria-labelledby="field-closeout-review-title">
                    <div class="field-closeout-dialog-panel">
                        <header class="field-closeout-dialog-header">
                            <div><p class="text-sm font-bold text-brand-blue">{{ $visit->displayLabel() }}</p><h2 id="field-closeout-review-title" class="mt-1 text-xl font-bold text-slate-950">Review closeout</h2></div>
                            <button type="button" class="button-secondary min-w-11 px-3" data-closeout-dialog-close aria-label="Close closeout review">Close</button>
                        </header>
                        <form method="POST" action="{{ route('field.visits.submit', $visit) }}" class="contents">
                            @csrf
                            <input type="hidden" name="submission_token" value="{{ Str::uuid() }}">
                            <div class="field-closeout-dialog-body">
                                <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <span class="text-sm font-semibold text-slate-600">Selected outcome</span>
                                    <span data-selected-outcome data-outcome="{{ $selectedOutcome }}" class="field-selected-outcome">{{ $outcomeLabels[$selectedOutcome] ?? 'Not selected' }}</span>
                                </div>
                                @if($closeoutMissing->isNotEmpty())
                                    <section class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4" aria-labelledby="closeout-missing-heading">
                                        <h3 id="closeout-missing-heading" class="font-bold text-amber-950">{{ $closeoutMissing->count() === 1 ? '1 required item remains' : $closeoutMissing->count().' required items remain' }}</h3>
                                        <ul class="mt-3 space-y-2 text-sm text-amber-950">@foreach($closeoutMissing as $field => $message)<li><button type="button" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-amber-300 bg-white px-3 py-2 text-left font-semibold hover:border-brand-blue focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue" data-closeout-fix-target="{{ $field }}"><span>{{ $message }}</span><span class="shrink-0 text-brand-blue">Fix</span></button></li>@endforeach</ul>
                                    </section>
                                @elseif($closeout?->representative_name)
                                    <label class="mt-5 flex min-h-11 gap-3 rounded-lg border border-slate-300 p-4 @error('acknowledgment_confirmed') border-red-500 bg-red-50 @enderror">
                                        <input type="checkbox" name="acknowledgment_confirmed" value="1" required @error('acknowledgment_confirmed') aria-invalid="true" aria-describedby="acknowledgment_confirmed-error" @enderror>
                                        <span class="text-sm font-semibold">I confirm the work and outcome were reviewed with {{ $closeout->representative_name }}.</span>
                                    </label>
                                    <x-field-error field="acknowledgment_confirmed" />
                                @else
                                    <div class="mt-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900">The saved outcome, acknowledgment fallback, and evidence requirements are ready.</div>
                                @endif
                                @unless($canSubmitCloseout)<p class="mt-5 rounded-lg border border-slate-300 bg-slate-50 p-4 text-sm font-semibold text-slate-700">Only the assigned lead or an authorized supervisor can submit this shared closeout.</p>@endunless
                            </div>
                            <footer class="field-closeout-dialog-footer">
                                <button type="button" class="button-secondary" data-closeout-dialog-close>Continue editing</button>
                                @if($closeoutMissing->isEmpty() && $canSubmitCloseout)<button class="button-action">Submit closeout</button>@endif
                            </footer>
                        </form>
                    </div>
                </dialog>
                <div class="h-16" aria-hidden="true"></div>
            @endif
        @endif
        @endif
    @endcan

    <section class="surface mt-4 p-5">
        <h2 class="font-bold">Ticket visit history</h2>
        @foreach ($visit->serviceTicket->visits as $history)
            <p class="mt-2 text-sm">{{ $history->displayLabel() }} · {{ ucfirst(str_replace('_', ' ', $history->status)) }}</p>
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
