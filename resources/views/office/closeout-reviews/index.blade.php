<x-layouts.office title="Closeout review" width="workspace">
    @if(session('status'))<x-office.alert type="success">{{ session('status') }}</x-office.alert>@endif
    @php($reviewFilterLabels = collect(['customer','outcome','priority','technician','age','correction_state'])->filter(fn ($key) => filled(request($key)))->mapWithKeys(fn ($key) => [$key => Str::headline($key).': '.Str::headline(request($key))]))
    <form method="GET" aria-label="Closeout review filters">
    <x-office.primary-toolbar title="Closeout queue" description="Review submitted field work, corrections, and customer outcomes." eyebrow="Office review">
        <x-slot:search><label class="sr-only" for="customer">Search customer</label><input class="form-input" id="customer" name="customer" value="{{ request('customer') }}" placeholder="Search customer name"></x-slot:search>
        <x-slot:filters><x-office.filter-panel :active-count="$reviewFilterLabels->count()"><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div><label class="form-label" for="outcome">Outcome</label><select class="form-input" id="outcome" name="outcome"><option value="">All outcomes</option>@foreach(['resolved','needs_return_trip','on_hold','customer_unavailable'] as $outcome)<option value="{{ $outcome }}" @selected(request('outcome')===$outcome)>{{ ucfirst(str_replace('_',' ',$outcome)) }}</option>@endforeach</select></div>
        <div><label class="form-label" for="priority">Priority</label><select class="form-input" id="priority" name="priority"><option value="">All priorities</option>@foreach(config('service_tickets.priorities') as $key=>$label)<option value="{{ $key }}" @selected(request('priority')===$key)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="technician">Submitted by</label><select class="form-input" id="technician" name="technician"><option value="">Anyone</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}" @selected((string)request('technician')===(string)$technician->id)>{{ $technician->name }}</option>@endforeach</select></div>
        <div><label class="form-label" for="age">Age</label><select class="form-input" id="age" name="age"><option value="">Any age</option><option value="1" @selected(request('age')==='1')>At least 1 day</option><option value="3" @selected(request('age')==='3')>At least 3 days</option><option value="7" @selected(request('age')==='7')>At least 7 days</option></select></div>
        <div><label class="form-label" for="correction_state">Submission</label><select class="form-input" id="correction_state" name="correction_state"><option value="">All submissions</option><option value="first_submission" @selected(request('correction_state')==='first_submission')>First submission</option><option value="resubmitted" @selected(request('correction_state')==='resubmitted')>Resubmitted</option></select></div>
        </div><div class="mt-4 flex flex-wrap justify-end gap-2"><a href="{{ route('office.closeout-reviews.index') }}" class="button-secondary">Clear all</a><button class="button-primary">Apply filters</button></div></x-office.filter-panel></x-slot:filters>
        @if($reviewFilterLabels->isNotEmpty())
            <x-slot:chips><span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>@foreach($reviewFilterLabels as $key => $label)<x-office.filter-chip :label="$label" :remove-url="route('office.closeout-reviews.index', request()->except([$key, 'page']))" />@endforeach<a href="{{ route('office.closeout-reviews.index') }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a></x-slot:chips>
        @endif
    </x-office.primary-toolbar>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table">
            <caption class="sr-only">Submitted closeouts awaiting review</caption>
            <thead><tr><th scope="col">Service Ticket</th><th scope="col">Customer and location</th><th scope="col">Visit</th><th scope="col">Outcome</th><th scope="col">Submitted by</th><th scope="col">Submitted</th><th scope="col" class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse($closeouts as $closeout)
                    <tr>
                        <td><a href="{{ route('office.closeout-reviews.show',$closeout) }}" class="font-bold text-brand-blue hover:text-brand-blue-deep">{{ $closeout->visit->serviceTicket->ticket_number }}</a><p class="mt-0.5 text-xs text-slate-500">Version {{ $closeout->version }}</p></td>
                        <td><p class="font-semibold text-slate-900">{{ $closeout->visit->serviceTicket->customer->display_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $closeout->visit->serviceLocation->name }}</p></td>
                        <td>{{ $closeout->visit->displayNumber() }}</td>
                        <td><span class="{{ in_array($closeout->visit->serviceTicket->priority,['high','urgent'],true) ? 'status-priority' : 'status-active' }}">{{ ucfirst(str_replace('_',' ',$closeout->outcome)) }}</span></td>
                        <td>{{ $closeout->submittedBy?->name ?? 'Former user' }}</td>
                        <td><x-local-time :value="$closeout->submitted_at" :timezone="$closeout->visit->timezone" /></td>
                        <td class="text-right"><a href="{{ route('office.closeout-reviews.show',$closeout) }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue">Review<span class="sr-only"> {{ $closeout->visit->serviceTicket->ticket_number }}</span></a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-3"><x-office.state-panel title="Review queue clear" message="No submitted closeouts match these filters." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @forelse($closeouts as $closeout)
            <a href="{{ route('office.closeout-reviews.show',$closeout) }}" class="office-mobile-card">
                <div class="flex items-start justify-between gap-3"><div><p class="font-bold text-brand-blue">{{ $closeout->visit->serviceTicket->ticket_number }}</p><p class="mt-1 font-semibold text-slate-950">{{ $closeout->visit->serviceTicket->customer->display_name }}</p></div><span class="{{ in_array($closeout->visit->serviceTicket->priority,['high','urgent'],true) ? 'status-priority' : 'status-active' }}">{{ ucfirst(str_replace('_',' ',$closeout->outcome)) }}</span></div>
                <p class="mt-2 text-sm text-slate-600">{{ $closeout->visit->serviceLocation->name }} &middot; {{ $closeout->visit->displayLabel() }} &middot; Version {{ $closeout->version }}</p>
                <p class="mt-3 text-sm text-slate-500">{{ $closeout->submittedBy?->name ?? 'Former user' }} &middot; <x-local-time :value="$closeout->submitted_at" :timezone="$closeout->visit->timezone" /></p>
            </a>
        @empty
            <x-office.state-panel title="Review queue clear" message="No submitted closeouts match these filters." />
        @endforelse
    </div>
    <div class="mt-5">{{ $closeouts->links() }}</div>
</x-layouts.office>
