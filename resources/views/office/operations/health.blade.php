<x-layouts.office title="Operational health">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-bold uppercase tracking-[.12em] text-brand-blue">Beta observability</p>
            <h1 class="mt-1 text-3xl font-bold">Operational health</h1>
            <p class="mt-2 text-slate-600">Safe diagnostics for workflow, upload, queue, and billing-handoff failures.</p>
        </div>
        <div class="rounded-lg border {{ $failedJobs ? 'border-red-300 bg-red-50 text-red-900' : 'border-emerald-300 bg-emerald-50 text-emerald-900' }} px-4 py-3 text-sm font-bold">
            {{ $failedJobs }} failed queue {{ Str::plural('job', $failedJobs) }}
        </div>
    </div>

    @if(session('status'))<div class="mt-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif

    <form method="GET" class="surface mt-6 grid gap-3 p-4 md:grid-cols-4">
        <div><label class="form-label" for="status">Status</label><select class="form-input" id="status" name="status"><option value="">All</option><option value="open" @selected(request('status')==='open')>Open</option><option value="resolved" @selected(request('status')==='resolved')>Resolved</option></select></div>
        <div><label class="form-label" for="severity">Severity</label><select class="form-input" id="severity" name="severity"><option value="">All</option><option value="error" @selected(request('severity')==='error')>Error</option><option value="warning" @selected(request('severity')==='warning')>Warning</option></select></div>
        <div><label class="form-label" for="category">Category</label><select class="form-input" id="category" name="category"><option value="">All</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(request('category')===$category)>{{ ucfirst(str_replace('_',' ',$category)) }}</option>@endforeach</select></div>
        <div class="flex items-end gap-2"><button class="button-primary flex-1">Filter</button><a class="button-secondary" href="{{ route('office.operations.health') }}">Clear</a></div>
    </form>

    <div class="mt-6 space-y-3">
        @forelse($incidents as $incident)
            <article class="surface p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2"><span class="{{ $incident->severity==='error' ? 'status-danger' : 'status-warning' }}">{{ ucfirst($incident->severity) }}</span><h2 class="font-bold">{{ ucfirst(str_replace('_',' ',$incident->category)) }}</h2></div>
                        <p class="mt-2 text-sm text-slate-600">Occurred {{ $incident->occurrences }} {{ Str::plural('time', $incident->occurrences) }} · Last <x-local-time :value="$incident->last_occurred_at" :timezone="$activeOrganization->timezone" /></p>
                        <p class="mt-1 text-xs text-slate-500">Request {{ $incident->request_id ?: 'system scan' }}@if($incident->subject_id) · Record {{ $incident->subject_id }}@endif</p>
                        @if($incident->context)<p class="mt-2 text-xs text-slate-600">{{ collect($incident->context)->map(fn($value,$key) => str_replace('_',' ',$key).': '.(is_array($value) ? implode(', ',$value) : $value))->join(' · ') }}</p>@endif
                        @if(isset($incident->context['ticket_id']))<a class="mt-2 inline-flex min-h-11 items-center text-sm font-bold text-brand-blue" href="{{ route('office.service-tickets.show',$incident->context['ticket_id']) }}">Open Service Ticket</a>@elseif(isset($incident->context['closeout_id']))<a class="mt-2 inline-flex min-h-11 items-center text-sm font-bold text-brand-blue" href="{{ route('office.closeout-reviews.show',$incident->context['closeout_id']) }}">Open closeout</a>@endif
                    </div>
                    <div class="text-right">
                        <span class="{{ $incident->status==='open' ? 'status-active' : 'status-success' }}">{{ ucfirst($incident->status) }}</span>
                        @if($activeMembership->hasCapability('operations.health.manage'))
                            <form method="POST" action="{{ $incident->status==='open' ? route('office.operations.resolve',$incident) : route('office.operations.reopen',$incident) }}" class="mt-2">@csrf<button class="button-secondary">{{ $incident->status==='open' ? 'Resolve' : 'Reopen' }}</button></form>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="surface p-8 text-center"><h2 class="font-bold">No incidents match these filters</h2><p class="mt-2 text-sm text-slate-500">Health scans and operational failures will appear here without sensitive contents.</p></div>
        @endforelse
    </div>
    <div class="mt-6">{{ $incidents->links() }}</div>
</x-layouts.office>
