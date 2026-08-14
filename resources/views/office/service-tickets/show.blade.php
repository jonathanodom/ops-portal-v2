<x-layouts.office :title="$ticket->ticket_number">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    @if($errors->any() && !request()->filled('execution_visit') && !request()->filled('manual_closeout_visit'))<x-form-errors />@endif
    <a href="{{ route('office.service-tickets.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Service Tickets</a>
    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div><p class="font-bold text-brand-blue">{{ $ticket->ticket_number }}</p><h1 class="mt-1 text-3xl font-bold text-slate-950">{{ $ticket->title }}</h1><p class="mt-2 text-sm text-slate-500">{{ ucfirst($ticket->priority) }} priority · {{ ucfirst(str_replace('_',' ',$ticket->status)) }}</p></div>
        @if($activeMembership->hasCapability('dispatch.manage'))<div class="flex flex-wrap gap-2"><a href="{{ route('office.service-tickets.edit', $ticket) }}" class="button-secondary">Edit ticket</a>@if(in_array($ticket->status, ['open', 'on_hold'], true))<a href="{{ route('office.service-tickets.visits.create', $ticket) }}" class="button-primary">Add visit</a>@endif</div>@endif
    </div>
    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(300px,1fr)]">
        <div class="space-y-6">
            <section class="surface p-5"><h2 class="text-lg font-bold">Customer & location</h2><p class="mt-3 font-bold">{{ $ticket->customer->display_name }}</p><a class="mt-1 inline-flex min-h-11 items-center text-sm font-bold text-brand-blue" href="{{ route('office.locations.show', $ticket->serviceLocation) }}">{{ $ticket->serviceLocation->name }} · {{ $ticket->serviceLocation->formattedAddress() }}</a>@if($ticket->contact)<p class="mt-2 text-sm text-slate-600">Contact: {{ $ticket->contact->name }} @if($ticket->contact->phone)· {{ $ticket->contact->phone }}@endif</p>@endif</section>
            <section class="surface p-5"><h2 class="text-lg font-bold">Work scope</h2><p class="mt-3 whitespace-pre-line text-slate-700">{{ $ticket->description ?: 'No work scope recorded.' }}</p>@if($ticket->customer_visible_summary)<h3 class="mt-5 text-sm font-bold uppercase tracking-[.1em] text-slate-500">Customer-visible summary</h3><p class="mt-2 whitespace-pre-line text-slate-700">{{ $ticket->customer_visible_summary }}</p>@endif</section>
            <section class="surface overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 p-5"><h2 class="text-lg font-bold">Visits</h2></div>
                @if($ticket->visits->count() > 1 && !in_array($ticket->status, ['completed', 'canceled']))
                    <div class="border-b border-blue-200 bg-blue-50 p-5 text-sm text-blue-950">
                        <p class="font-bold">Closing a ticket with return trips</p>
                        <p class="mt-1">Submit the final return trip as Resolved, then approve that closeout in Review. The Service Ticket closes when every earlier visit is approved or canceled.</p>
                    </div>
                @endif
                @forelse($ticket->visits as $visit)
                    <div class="border-b border-slate-200 p-5 last:border-0">
                        <div class="flex flex-wrap justify-between gap-3"><div><p class="font-bold">{{ $visit->displayLabel() }}</p><p class="mt-1 text-sm text-slate-600">{{ ucfirst(str_replace('_',' ',$visit->status)) }} · {{ $visit->scheduledStartLocal()?->format('M j, Y g:i A T') ?? 'Unscheduled' }}@if($visit->scheduledEndLocal()) – {{ $visit->scheduledEndLocal()->format('g:i A T') }}@endif</p><p class="mt-1 text-sm text-slate-500">{{ $visit->assignments->map(fn($a) => ($a->is_lead ? 'Lead: ' : '').$a->membership->user->name)->join(', ') ?: 'No crew assigned' }}</p></div><div class="flex flex-wrap gap-2">@if(in_array($visit->id, $executableVisitIds, true))<button type="button" class="button-primary" data-dialog-open="execution-visit-{{ $visit->id }}">Open execution</button>@endif @if($activeMembership->hasCapability('dispatch.manage') && in_array($ticket->status, ['open', 'on_hold'], true) && in_array($visit->status, ['planned', 'scheduled', 'assigned'], true))<a class="button-secondary" href="{{ route('office.visits.edit', $visit) }}">Schedule / assign</a>@endif</div></div>
                        @if(in_array($visit->id, $manualCloseoutVisitIds, true))
                            <div class="mt-4">
                                @if($visit->currentCloseout)
                                    <button type="button" class="button-secondary" data-manual-closeout-open="manual-closeout-visit-{{ $visit->id }}">Manual closeout</button>
                                @else
                                    <form method="POST" action="{{ route('office.visits.manual-closeout.start', $visit) }}">@csrf<button class="button-secondary">Start manual closeout</button></form>
                                @endif
                            </div>
                        @endif
                        @if(in_array($visit->id, $archivableVisitIds, true))
                            <details class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                <summary class="flex min-h-11 cursor-pointer items-center font-bold text-slate-700">Archive visit</summary>
                                <form method="POST" action="{{ route('office.admin.archive.visits.store', $visit) }}" class="mt-3 space-y-3">
                                    @csrf
                                    <p class="text-sm text-slate-600">This removes {{ $visit->displayLabel() }} from operational queues and ticket-completion checks. It can be restored from Admin Archive.</p>
                                    <label class="form-label" for="archive_reason_{{ $visit->id }}">Archive reason</label>
                                    <textarea class="form-textarea" id="archive_reason_{{ $visit->id }}" name="reason" required maxlength="2000"></textarea>
                                    <label class="flex min-h-11 items-center gap-3 rounded-lg border border-orange-300 bg-orange-50 px-3">
                                        <input type="checkbox" name="confirm_archive" value="1" required>
                                        <span class="text-sm font-semibold">I understand this visit will leave active workflows.</span>
                                    </label>
                                    <button class="button-secondary w-full">Move to Admin Archive</button>
                                </form>
                            </details>
                        @endif
                        @if($activeMembership->hasCapability('dispatch.manage') && !in_array($visit->status, ['canceled', 'pending_closeout', 'customer_unavailable', 'approved'], true))
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                <form method="POST" action="{{ route('office.visits.cancel', $visit) }}" class="space-y-2">@csrf<div class="flex gap-2"><input class="form-input" name="reason" required placeholder="Cancellation reason"><button class="button-secondary">Cancel visit</button></div>@if($visit->timeEntries->whereNull('ended_at')->isNotEmpty())<label class="flex min-h-11 items-center gap-3 rounded-lg border border-orange-300 bg-orange-50 px-3"><input type="checkbox" name="confirm_stop_active_timers" value="1"><span class="text-sm font-semibold">Stop {{ $visit->timeEntries->whereNull('ended_at')->count() }} active timer(s) and cancel</span></label>@endif</form>
                                @if($visit->status === 'on_site')<form method="POST" action="{{ route('office.visits.return', $visit) }}" class="flex gap-2">@csrf<input class="form-input" name="reason" required placeholder="Return reason"><button class="button-secondary">Create return</button></form>@endif
                            </div>
                        @endif
                        @if($visit->currentCloseout)
                            @php($timeSeconds = $visit->timeEntries->sum(fn ($entry) => $entry->ended_at ? $entry->started_at->diffInSeconds($entry->ended_at) : 0))
                            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4"><p class="text-sm font-bold">Field closeout: {{ ucfirst($visit->currentCloseout->status) }}</p><p class="mt-1 text-sm text-slate-600">Crew time: {{ number_format($timeSeconds / 3600, 2) }} hours</p>
                            @if($administrativeReview = $visit->currentCloseout->reviews->firstWhere('administrative_completion', true))<p class="mt-2 text-sm font-semibold text-brand-orange"><span class="status-priority">Administratively completed</span> by {{ $administrativeReview->reviewer?->name ?? 'Super Admin' }} · <x-local-time :value="$administrativeReview->administratively_completed_at" :timezone="$activeOrganization->timezone" /></p>@endif
                            @if($visit->currentCloseout->status==='submitted' && $activeMembership->hasCapability('closeouts.inspect'))<a class="mt-3 inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ route('office.closeout-reviews.show',$visit->currentCloseout) }}">Open review packet</a>@endif
                            @if($visit->currentCloseout->status==='submitted' && $activeMembership->hasCapability('closeouts.inspect'))<div class="mt-3 space-y-2 text-sm"><p><strong>Outcome:</strong> {{ ucfirst(str_replace('_',' ',$visit->currentCloseout->outcome)) }}</p><p><strong>Diagnosis:</strong> {{ $visit->currentCloseout->diagnosis ?: '—' }}</p><p><strong>Work performed:</strong> {{ $visit->currentCloseout->work_performed ?: '—' }}</p><p><strong>Exceptions:</strong> {{ $visit->currentCloseout->exceptions ?: '—' }}</p><p><strong>Recommendations:</strong> {{ $visit->currentCloseout->recommendations ?: '—' }}</p><p><strong>Acknowledgment:</strong> {{ $visit->currentCloseout->representative_name ?: ucfirst(str_replace('_',' ',$visit->currentCloseout->ack_unavailable_category ?? 'fallback recorded')) }}</p><p><strong>Evidence:</strong> {{ $visit->currentCloseout->media->where('state','stored')->count() }} photos · {{ $visit->currentCloseout->parts->whereNull('removed_at')->count() }} proposals</p><div class="flex flex-wrap gap-2">@foreach($visit->currentCloseout->media->where('state','stored') as $media)<a class="button-secondary" href="{{ route('field.media.show',$media) }}">{{ ucfirst(str_replace('_',' ',$media->category)) }}</a>@endforeach</div>@foreach($visit->currentCloseout->parts->whereNull('removed_at') as $part)<p><strong>{{ $part->description }}</strong> · {{ $part->quantity }} {{ $part->unit }} · {{ ucfirst(str_replace('_',' ',$part->billing_treatment)) }}</p>@endforeach</div>@else<p class="mt-2 text-xs text-slate-500">Draft narrative and evidence remain field-only until submission.</p>@endif</div>
                        @endif
                        @if(in_array($visit->id, $executableVisitIds, true))@include('office.service-tickets._execution-dialog', ['visit' => $visit])@endif
                        @if(in_array($visit->id, $manualCloseoutVisitIds, true) && $visit->currentCloseout)@include('office.service-tickets._manual-closeout-dialog', ['visit' => $visit, 'closeout' => $visit->currentCloseout])@endif
                    </div>
                @empty<div class="p-6 text-sm text-slate-500">No visits yet. This ticket remains in the unscheduled ticket list until a visit is created.</div>@endforelse
            </section>
            <section class="surface p-5"><h2 class="text-lg font-bold">Internal notes</h2>
                @if($activeMembership->hasCapability('dispatch.manage'))<form method="POST" action="{{ route('office.service-tickets.notes.store', $ticket) }}" class="mt-4">@csrf<label class="form-label" for="body">Add note</label><textarea class="form-textarea" id="body" name="body" required maxlength="10000"></textarea><button class="button-primary mt-3">Add note</button></form>@endif
                <div class="mt-5 space-y-4">@forelse($ticket->notes->sortByDesc('created_at') as $note)<article class="border-l-4 border-slate-200 pl-4"><p class="whitespace-pre-line text-sm text-slate-700">{{ $note->body }}</p><p class="mt-2 text-xs font-semibold text-slate-500">{{ $note->author?->name ?? 'Former user' }} · <x-local-time :value="$note->created_at" :timezone="$activeOrganization->timezone" /></p></article>@empty<p class="text-sm text-slate-500">No internal notes.</p>@endforelse</div>
            </section>
            @if($activeMembership->hasCapability('invoices.view'))
                <section class="surface p-5"><h2 class="text-lg font-bold">Invoice history</h2><div class="mt-4 space-y-3">@forelse($ticket->invoices as $invoice)<article class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4"><div><a class="font-bold text-brand-blue" href="{{ route('office.invoices.show',$invoice) }}">{{ $invoice->invoice_number }}</a><p class="mt-1 text-sm text-slate-600">{{ ucfirst(str_replace('_',' ',$invoice->status)) }} · Generation {{ $invoice->generation }}@if($invoice->issued_at) · Issued <x-local-time :value="$invoice->issued_at" :timezone="$activeOrganization->timezone" />@endif</p>@if($invoice->acknowledgments_exists)<p class="mt-1 text-xs font-semibold text-emerald-700">Customer presentation acknowledged</p>@endif</div><p class="text-lg font-bold">${{ number_format($invoice->total_cents/100,2) }}</p></article>@empty<p class="text-sm text-slate-500">No invoice has been created for this Service Ticket.</p>@endforelse</div></section>
            @endif
        </div>
        <aside class="space-y-6">
            @if($activeMembership->hasCapability('dispatch.manage'))
                @if(in_array($ticket->status, ['completed', 'canceled'], true))
                    <section class="surface border-orange-200 p-5">
                        <h2 class="font-bold">{{ $ticket->status === 'completed' ? 'Reopen for Callback' : 'Reopen Service Ticket' }}</h2>
                        <p class="mt-2 text-sm text-slate-600">Prior Visits, Closeouts, invoices, payments, and Billing Handoff history remain unchanged. Reopening permits a new callback Visit.</p>
                        <form method="POST" action="{{ route('office.service-tickets.reopen', $ticket) }}" class="mt-4 space-y-3">
                            @csrf
                            <label class="form-label" for="reopen_reason">Callback reason</label>
                            <textarea class="form-textarea" id="reopen_reason" name="reason" required maxlength="2000" aria-describedby="reopen-help">{{ old('reason') }}</textarea>
                            <p id="reopen-help" class="text-sm text-slate-500">Required. This reason becomes part of the permanent callback history.</p>
                            <button class="button-primary w-full">{{ $ticket->status === 'completed' ? 'Reopen for Callback' : 'Reopen Service Ticket' }}</button>
                        </form>
                    </section>
                @else
                    <section class="surface p-5"><h2 class="font-bold">Ticket status</h2><form method="POST" action="{{ route('office.service-tickets.transition', $ticket) }}" class="mt-4 space-y-3">@csrf<select class="form-input" name="status" aria-label="New ticket status">@if($ticket->status==='open')<option value="on_hold">Put on hold</option>@endif @if($ticket->status==='on_hold')<option value="open">Reopen</option>@endif<option value="canceled">Cancel ticket</option></select><textarea class="form-textarea" name="reason" placeholder="Reason required for hold or cancellation"></textarea>@php($activeTicketTimers = $ticket->visits->flatMap->timeEntries->whereNull('ended_at'))@if($activeTicketTimers->isNotEmpty())<label class="flex min-h-11 items-center gap-3 rounded-lg border border-orange-300 bg-orange-50 px-3"><input type="checkbox" name="confirm_stop_active_timers" value="1"><span class="text-sm font-semibold">Stop {{ $activeTicketTimers->count() }} active timer(s) if canceling</span></label>@endif<button class="button-secondary w-full">Update status</button></form></section>
                @endif
            @endif
            @if($ticket->reopens->isNotEmpty())
                <section class="surface p-5"><h2 class="font-bold">Callback history</h2><div class="mt-4 space-y-4">@foreach($ticket->reopens as $reopen)<article class="border-l-4 border-orange-300 pl-4"><p class="text-sm font-semibold text-slate-700">Reopened from {{ ucfirst($reopen->from_status) }}</p><p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $reopen->reason }}</p><p class="mt-2 text-xs font-semibold text-slate-500">{{ $reopen->reopenedBy?->name ?? 'Former user' }} · <x-local-time :value="$reopen->reopened_at" :timezone="$activeOrganization->timezone" /></p></article>@endforeach</div></section>
            @endif
            <section id="history" class="surface scroll-mt-6 p-5"><h2 class="font-bold">History</h2><div class="mt-4 space-y-4">@forelse($events as $event)<div><p class="text-sm font-semibold text-slate-700">{{ str_replace(['.','_'],' ',ucfirst($event->event_type)) }}</p><p class="mt-1 text-xs text-slate-500">{{ $event->actor?->name ?? 'System' }} · <x-local-time :value="$event->occurred_at" :timezone="$activeOrganization->timezone" format="M j, g:i A T" /></p></div>@empty<p class="text-sm text-slate-500">No history yet.</p>@endforelse</div></section>
        </aside>
    </div>
</x-layouts.office>
