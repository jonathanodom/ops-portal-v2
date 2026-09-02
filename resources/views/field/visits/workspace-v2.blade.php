<x-layouts.field :title="$visit->serviceTicket->ticket_number">
    @php
        $closeout = $visit->currentCloseout;
        $selectedOutcome = old('outcome', $closeout?->outcome);
        $ticketPurpose = $visit->serviceTicket->canonicalPurpose();
        $installationCloseout = $ticketPurpose === \App\Domain\ServiceTicketPurpose::INSTALLATION_PROJECT;
        $serviceVisitCloseout = $ticketPurpose === \App\Domain\ServiceTicketPurpose::SERVICE_VISIT;
        $siteSurveyCloseout = $ticketPurpose === \App\Domain\ServiceTicketPurpose::SITE_SURVEY;
        $warrantyMaintenanceCloseout = $ticketPurpose === \App\Domain\ServiceTicketPurpose::WARRANTY_MAINTENANCE;
        $internalTestingCloseout = $ticketPurpose === \App\Domain\ServiceTicketPurpose::INTERNAL_TESTING;
        $outcomeLabels = match ($ticketPurpose) {
            \App\Domain\ServiceTicketPurpose::SITE_SURVEY => ['resolved' => 'Survey Complete', 'needs_return_trip' => 'Return Visit Required', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'],
            \App\Domain\ServiceTicketPurpose::INSTALLATION_PROJECT => ['resolved' => 'Completed', 'needs_return_trip' => 'Return Visit Required', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'],
            \App\Domain\ServiceTicketPurpose::SERVICE_VISIT => ['resolved' => 'Resolved', 'needs_return_trip' => 'Return Visit Required', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'Temporarily Resolved / On Hold'],
            \App\Domain\ServiceTicketPurpose::WARRANTY_MAINTENANCE => ['resolved' => 'Completed', 'needs_return_trip' => 'Return Visit Required', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'],
            \App\Domain\ServiceTicketPurpose::INTERNAL_TESTING => ['resolved' => 'Completed / Passed', 'needs_return_trip' => 'Follow-up Required', 'customer_unavailable' => 'Unavailable', 'on_hold' => 'On hold'],
            default => ['resolved' => 'Resolved', 'needs_return_trip' => 'Needs return trip', 'customer_unavailable' => 'Customer unavailable', 'on_hold' => 'On hold'],
        };
        $contact = $visit->serviceTicket->contact ?? $visit->serviceLocation->primaryContact;
        $activeParts = $closeout?->parts?->whereNull('removed_at') ?? collect();
        $activeMedia = $closeout?->media?->where('state', 'stored') ?? collect();
        $inheritedMedia = ($versions ?? collect())->where('id', '!=', $closeout?->id)->flatMap->media->where('state', 'stored');
        $closeoutMissing = collect($closeoutReadinessErrors ?? ['outcome' => 'Choose an outcome.']);
        $signaturePath = ! $internalTestingCloseout && $closeout && blank($closeout->ack_unavailable_category) && in_array($closeout->outcome, ['resolved', 'needs_return_trip', 'on_hold'], true);
        $activeTimer = $closeout?->timeEntries?->first(fn ($entry) => $entry->active_user_id === auth()->id());
        $workItemWritable = in_array($visit->serviceTicket->status, ['open', 'on_hold'], true)
            && in_array($visit->status, ['on_site', 'returned_for_correction'], true)
            && $closeout?->status === 'draft';
        $workspaceWritable = $visit->status !== 'canceled' && (! $closeout || $closeout->status === 'draft');
        $canSubmitCloseout = $activeMembership->hasCapability('visits.execute_any') || $visit->assignments->contains(fn ($assignment) => $assignment->membership->user_id === auth()->id() && $assignment->is_lead);
        $handledItems = $visit->serviceTicket->workItems->filter(fn ($item) => $item->visits->contains('id', $visit->id));
        $completedItems = $handledItems->where('status', 'completed');
        $followUpItems = $handledItems->where('status', 'needs_follow_up');
        $completedHelper = collect([$visit->serviceTicket->description])->filter()->merge($completedItems->map(fn ($item) => '- '.$item->title.($item->work_note ? ': '.$item->work_note : '')))->join("\n");
        $followUpHelper = $followUpItems->map(fn ($item) => '- '.$item->title.($item->followUpServiceTicket ? ' ('.$item->followUpServiceTicket->ticket_number.')' : '').($item->work_note ? ': '.$item->work_note : ''))->join("\n");
        $mapsUrl = $visit->serviceLocation->mapsUrl();
    @endphp

    <div data-field-workspace-v2 data-visit-id="{{ $visit->id }}" data-service-visit="{{ $serviceVisitCloseout ? 'true' : 'false' }}" data-ticket-purpose="{{ $ticketPurpose }}">
        <script type="application/json" data-v2-initial-readiness>@json($closeoutMissing)</script>
        @if(session('status'))<div class="mb-3 rounded-lg border border-emerald-400 bg-emerald-50 p-3 text-sm font-semibold text-emerald-950" role="status">{{ session('status') }}</div>@endif
        <x-form-errors />

        @if($visit->status === 'returned_for_correction' && $closeout?->parent)
            @php($returnReview = $closeout->parent->reviews->firstWhere('decision', 'returned'))
            <div class="mb-3 rounded-lg border border-orange-300 bg-orange-50 p-4 text-orange-950" role="alert"><p class="font-bold">Returned for correction · Version {{ $closeout->version }}</p><p class="mt-1 text-sm">{{ $returnReview?->reason ?: 'Review and resubmit this corrected version.' }}</p><p class="mt-2 text-xs font-semibold">Prior acknowledgment, time, and evidence remain read-only.</p></div>
        @endif

        <header class="field-v2-command">
            <div class="min-w-0">
                <a href="{{ route('field.home') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Today</a>
                <p class="truncate text-xs font-bold uppercase tracking-wide text-brand-blue">{{ $visit->serviceTicket->ticket_number }} · {{ $visit->displayLabel() }}</p>
                <h1 class="truncate text-lg font-bold text-slate-950">{{ $visit->serviceTicket->title }}</h1>
                <p class="truncate text-sm font-semibold text-slate-600">{{ $visit->serviceLocation->name }} · {{ Str::headline($visit->status) }}</p>
            </div>
            <div class="shrink-0 text-right">
                @if($activeTimer)
                    <p class="text-xs font-bold uppercase tracking-wide text-brand-orange">Current timer</p>
                    <p class="font-bold tabular-nums" data-v2-live-timer data-started-at="{{ $activeTimer->effective_started_at->toIso8601String() }}">Running</p>
                    <p class="max-w-36 truncate text-xs text-slate-600">{{ $activeTimer->workItem?->title ?? Str::headline($activeTimer->category) }}</p>
                @else
                    <p class="text-xs font-semibold text-slate-500">No active timer</p>
                @endif
                <a href="{{ route('field.visits.classic', $visit) }}" class="inline-flex min-h-11 items-center text-xs font-bold text-brand-blue">Classic workspace</a>
            </div>
            @can('execute', $visit)
                <div class="col-span-2 grid grid-cols-2 gap-2">
                    @if($visit->status === 'assigned')<form class="col-span-2" method="POST" action="{{ route('field.visits.transition', $visit) }}">@csrf<input type="hidden" name="status" value="en_route"><button class="button-action w-full">Start En Route</button></form>@endif
                    @if($visit->status === 'en_route')<form class="col-span-2" method="POST" action="{{ route('field.visits.transition', $visit) }}">@csrf<input type="hidden" name="status" value="on_site"><button class="button-action w-full">Mark On Site</button></form>@endif
                    @if($activeTimer?->category === 'on_site')<button type="button" class="button-secondary" data-v2-go-tab="work">Switch work</button>@endif
                    @if(in_array($visit->status, ['on_site', 'returned_for_correction'], true) && $workspaceWritable)<button type="button" class="button-action {{ $activeTimer?->category === 'on_site' ? '' : 'col-span-2' }}" data-v2-finish-open>Finish Visit</button>@endif
                </div>
            @endcan
        </header>

        @if($visit->status === 'canceled')<div class="mt-3 rounded-lg border border-slate-300 bg-slate-100 p-4" role="status"><p class="font-bold">Canceled Visit · read-only</p><p class="mt-1 text-sm">This visit was canceled. Completed time remains available under Time.</p></div>@endif

        @if($visit->serviceTicket->isReturnFollowUp() && $visit->serviceTicket->returnFollowUpSourceTicket && $visit->serviceTicket->returnFollowUpSourceCloseout)
            @php($sourceCloseout = $visit->serviceTicket->returnFollowUpSourceCloseout)
            <section class="mt-3 border border-brand-orange bg-orange-50 p-4 text-sm text-orange-950" data-field-return-follow-up>
                <p class="text-xs font-bold uppercase tracking-wide text-orange-800">Return Visit Follow-Up</p>
                <h2 class="mt-1 font-bold">From {{ $visit->serviceTicket->returnFollowUpSourceTicket->ticket_number }} · {{ \App\Domain\ServiceTicketPurpose::label($visit->serviceTicket->return_follow_up_original_purpose) }}</h2>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2"><dt class="font-bold">Return Reason</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $sourceCloseout->return_reason }}</dd></div>
                    @if(filled($sourceCloseout->unfinished_work))<div class="sm:col-span-2"><dt class="font-bold">Unfinished Work</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $sourceCloseout->unfinished_work }}</dd></div>@endif
                    @if(filled($sourceCloseout->needed_equipment))<div class="sm:col-span-2"><dt class="font-bold">Needed Parts / Equipment</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $sourceCloseout->needed_equipment }}</dd></div>@endif
                    @if($sourceCloseout->submittedBy)<div><dt class="font-bold">Original Visit Technician</dt><dd class="mt-1">{{ $sourceCloseout->submittedBy->name }}</dd></div>@endif
                    @if($activeMembership->hasCapability('experience.office.access'))<div><a class="inline-flex min-h-11 items-center font-bold text-brand-blue underline" href="{{ route('office.service-tickets.show', $visit->serviceTicket->returnFollowUpSourceTicket) }}">Open source ticket</a></div>@endif
                </dl>
            </section>
        @endif

        <nav class="field-v2-tabs" aria-label="Visit workspace" role="tablist">
            @foreach([
                'overview' => ['Overview', null],
                'work' => ['Work', $handledItems->where('status', 'open')->count() ? $handledItems->where('status', 'open')->count().' open' : $handledItems->count().' items'],
                'time' => ['Time', $activeTimer ? 'Running' : (($closeout?->timeEntries?->count() ?? 0).' entries')],
                'evidence' => ['Evidence', $activeMedia->count().' photos'],
                'closeout' => ['Closeout', $closeoutMissing->isEmpty() ? 'Ready' : $closeoutMissing->count().' missing'],
            ] as $tab => [$label, $status])
                <button type="button" role="tab" id="field-v2-tab-{{ $tab }}" aria-controls="field-v2-panel-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" data-v2-tab="{{ $tab }}"><span>{{ $label }}</span>@if($status)<small data-v2-tab-status="{{ $tab }}">{{ $status }}</small>@endif</button>
            @endforeach
        </nav>

        <div class="mt-3" data-v2-panels data-v2-swipe-surface>
            <section id="field-v2-panel-overview" role="tabpanel" aria-labelledby="field-v2-tab-overview" tabindex="0" data-v2-panel="overview" class="space-y-3">
                <div class="surface p-5">
                    <h2 class="text-lg font-bold">Customer &amp; site</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><p class="font-bold">{{ $visit->serviceTicket->customer->display_name }}</p><p>{{ $visit->serviceLocation->name }}<br>{{ $visit->serviceLocation->formattedAddress() }}</p></div>
                        <div>@if($contact)<p class="font-bold">{{ $contact->name }}</p>@else<p class="text-sm text-slate-500">No designated contact.</p>@endif</div>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($contact?->phone)<a class="button-secondary" href="tel:{{ $contact->phone }}">Call</a>@endif
                        @if($contact?->email)<a class="button-secondary" href="mailto:{{ $contact->email }}">Email</a>@endif
                        @if($mapsUrl)
                            <details class="relative" data-field-navigation>
                                <summary class="button-secondary cursor-pointer list-none">Navigate</summary>
                                <div class="absolute left-0 z-20 mt-1 grid min-w-44 gap-1 border border-slate-300 bg-white p-2 shadow-lg">
                                    <a class="button-secondary w-full" href="{{ $mapsUrl }}" target="_blank" rel="noopener">Open Maps</a>
                                    @can('execute', $visit)
                                        @if(in_array($visit->status, ['assigned', 'en_route'], true))
                                            <form method="POST" action="{{ route('field.visits.start-route', $visit) }}">@csrf<button class="button-action w-full">Start Route</button></form>
                                        @endif
                                    @endcan
                                </div>
                            </details>
                        @else
                            <span class="inline-flex min-h-11 items-center text-sm font-semibold text-slate-500">No map address</span>
                        @endif
                    </div>
                    @if($visit->serviceLocation->access_instructions)<div class="mt-4 border-t border-slate-200 pt-4"><h3 class="text-sm font-bold text-brand-orange">Access instructions</h3><p class="mt-1 whitespace-pre-line">{{ $visit->serviceLocation->access_instructions }}</p></div>@endif
                </div>
                <div class="surface p-5"><h2 class="text-lg font-bold">Primary scope</h2><p class="mt-2 font-semibold">{{ $visit->serviceTicket->title }}</p><p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $visit->serviceTicket->description ?: 'No detailed scope recorded.' }}</p></div>
                <div class="surface p-5"><div class="grid gap-4 sm:grid-cols-2"><div><h2 class="font-bold">Schedule</h2><p class="mt-1 text-sm">{{ $visit->scheduledStartLocal()?->format('M j, Y · g:i A T') ?? 'Unscheduled' }}@if($visit->scheduledEndLocal())<br>to {{ $visit->scheduledEndLocal()->format('g:i A T') }}@endif</p></div><div><h2 class="font-bold">Crew</h2>@foreach($visit->assignments as $assignment)<p class="mt-1 text-sm">{{ $assignment->membership->user->name }}{{ $assignment->is_lead ? ' · Lead' : '' }}</p>@endforeach</div></div>@if($visit->returnOfVisit)<p class="mt-4 border-t border-slate-200 pt-4 text-sm font-semibold text-brand-orange">Return of {{ $visit->returnOfVisit->displayNumber() }}</p>@endif</div>
            </section>

            @include('field.visits._workspace-v2-work')
            @include('field.visits._workspace-v2-time')
            @include('field.visits._workspace-v2-evidence')
            @include('field.visits._workspace-v2-closeout')
        </div>
    </div>
</x-layouts.field>
