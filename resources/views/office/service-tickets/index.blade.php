<x-layouts.office title="Service Tickets" width="workspace">
    @php
        $ticketFilterLabels = [];
        foreach (['search' => 'Search', 'status' => 'Status', 'priority' => 'Priority', 'source' => 'Source', 'purpose' => 'Purpose', 'billing_disposition' => 'Billing'] as $key => $label) if (filled(request($key))) $ticketFilterLabels[$key] = $label.': '.Str::headline(request($key));
        if (filled(request('assignee'))) $ticketFilterLabels['assignee'] = 'Assignee: '.($memberships->firstWhere('id', (int) request('assignee'))?->user?->name ?? request('assignee'));
        $hasFilters = count($ticketFilterLabels) > 0;
    @endphp
    <form method="GET" aria-label="Service Ticket filters">
    <x-office.primary-toolbar title="Service Tickets" description="Find, prioritize, and open active service work." eyebrow="Operations">
        <x-slot:search><label class="sr-only" for="search">Search Service Tickets</label><input class="form-input" id="search" name="search" value="{{ request('search') }}" placeholder="Search ticket, customer, location, or title"></x-slot:search>
        <x-slot:filters><x-office.filter-panel :active-count="count($ticketFilterLabels)"><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><div><label class="form-label" for="status">Status</label><select class="form-input" id="status" name="status"><option value="">All statuses</option>@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="priority">Priority</label><select class="form-input" id="priority" name="priority"><option value="">All priorities</option>@foreach($priorities as $value=>$label)<option value="{{ $value }}" @selected(request('priority')===$value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="source">Source</label><select class="form-input" id="source" name="source"><option value="">All sources</option>@foreach($sources as $value=>$label)<option value="{{ $value }}" @selected(request('source')===$value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="purpose">Purpose</label><select class="form-input" id="purpose" name="purpose"><option value="">All purposes</option>@foreach($purposes as $value=>$label)<option value="{{ $value }}" @selected(request('purpose')===$value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="billing_disposition">Billing</label><select class="form-input" id="billing_disposition" name="billing_disposition"><option value="">All billing</option>@foreach($billingDispositions as $value=>$label)<option value="{{ $value }}" @selected(request('billing_disposition')===$value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="assignee">Assignee</label><select class="form-input" id="assignee" name="assignee"><option value="">All assignees</option>@foreach($memberships as $membership)<option value="{{ $membership->id }}" @selected((string)request('assignee')===(string)$membership->id)>{{ $membership->user->name }}</option>@endforeach</select></div></div><div class="mt-4 flex flex-wrap justify-end gap-2"><a href="{{ route('office.service-tickets.index') }}" class="button-secondary">Clear all</a><button class="button-primary">Apply filters</button></div></x-office.filter-panel></x-slot:filters>
        @if($activeMembership->hasCapability('dispatch.manage'))
            <x-slot:primaryAction><a href="{{ route('office.service-tickets.create') }}" class="button-primary">New service ticket</a></x-slot:primaryAction>
        @endif
        @if($ticketFilterLabels)
            <x-slot:chips><span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>@foreach($ticketFilterLabels as $key => $label)<x-office.filter-chip :label="$label" :remove-url="route('office.service-tickets.index', request()->except([$key, 'page']))" />@endforeach<a href="{{ route('office.service-tickets.index') }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a></x-slot:chips>
        @endif
    </x-office.primary-toolbar>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table">
            <caption class="sr-only">Service Ticket directory</caption>
            <thead><tr><th scope="col">Service Ticket</th><th scope="col">Customer and location</th><th scope="col">Purpose / billing</th><th scope="col">Visits</th><th scope="col">Priority</th><th scope="col">Status</th><th scope="col" class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse($tickets as $ticket)
                    <tr>
                        <td><a href="{{ route('office.service-tickets.show',$ticket) }}" class="font-bold text-brand-blue hover:text-brand-blue-deep" aria-label="{{ $ticket->ticket_number }}: {{ $ticket->title }}">{{ $ticket->ticket_number }}</a><p class="mt-0.5 max-w-md font-semibold text-slate-950">{{ $ticket->title }}</p></td>
                        <td><p class="font-semibold text-slate-900">{{ $ticket->customer->display_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $ticket->serviceLocation->name }}</p></td>
                        <td><p>{{ $purposes[$ticket->purpose] ?? ucfirst(str_replace('_',' ',$ticket->purpose)) }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $billingDispositions[$ticket->billing_disposition] ?? ucfirst(str_replace('_',' ',$ticket->billing_disposition)) }}</p></td>
                        <td>{{ $ticket->visits_count }}</td>
                        <td><span class="{{ in_array($ticket->priority,['high','urgent'],true) ? 'status-priority' : 'status-active' }}">{{ $priorities[$ticket->priority] ?? ucfirst($ticket->priority) }}</span></td>
                        <td><span class="{{ $ticket->status==='on_hold' ? 'status-hold' : ($ticket->status==='canceled' ? 'status-inactive' : 'status-active') }}">{{ $statuses[$ticket->status] ?? ucfirst(str_replace('_',' ',$ticket->status)) }}</span></td>
                        <td class="text-right"><a href="{{ route('office.service-tickets.show',$ticket) }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue">Open<span class="sr-only"> {{ $ticket->ticket_number }}</span></a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-3"><x-office.state-panel :title="$hasFilters ? 'No service tickets match these filters.' : 'No service tickets found.'" :message="$hasFilters ? 'Clear filters to broaden the queue.' : 'Create the first Service Ticket when work is ready.'" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @forelse($tickets as $ticket)
            <a href="{{ route('office.service-tickets.show',$ticket) }}" class="office-mobile-card">
                <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="font-bold text-brand-blue">{{ $ticket->ticket_number }}</p><p class="mt-1 font-semibold text-slate-950">{{ $ticket->title }}</p></div><span class="{{ in_array($ticket->priority,['high','urgent'],true) ? 'status-priority' : 'status-active' }}">{{ $priorities[$ticket->priority] ?? ucfirst($ticket->priority) }}</span></div>
                <p class="mt-3 text-sm font-semibold text-slate-800">{{ $ticket->customer->display_name }}</p><p class="mt-0.5 text-sm text-slate-500">{{ $ticket->serviceLocation->name }}</p>
                <p class="mt-3 text-sm text-slate-600">{{ $purposes[$ticket->purpose] ?? ucfirst(str_replace('_',' ',$ticket->purpose)) }} · {{ $billingDispositions[$ticket->billing_disposition] ?? ucfirst(str_replace('_',' ',$ticket->billing_disposition)) }}</p><div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm"><span class="{{ $ticket->status==='on_hold' ? 'status-hold' : ($ticket->status==='canceled' ? 'status-inactive' : 'status-active') }}">{{ $statuses[$ticket->status] ?? ucfirst(str_replace('_',' ',$ticket->status)) }}</span><span class="font-semibold text-slate-600">{{ $ticket->visits_count }} {{ Str::plural('visit',$ticket->visits_count) }}</span></div>
            </a>
        @empty
            <x-office.state-panel :title="$hasFilters ? 'No service tickets match these filters.' : 'No service tickets found.'" :message="$hasFilters ? 'Clear filters to broaden the queue.' : 'Create the first Service Ticket when work is ready.'" />
        @endforelse
    </div>
    <div class="mt-5">{{ $tickets->links() }}</div>
</x-layouts.office>
