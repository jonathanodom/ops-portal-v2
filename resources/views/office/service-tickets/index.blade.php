<x-layouts.office title="Service Tickets">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-sm font-bold uppercase tracking-[.12em] text-brand-blue">Operations</p><h1 class="mt-1 text-3xl font-bold text-slate-950">Service Tickets</h1></div>
        @if($activeMembership->hasCapability('dispatch.manage'))<a href="{{ route('office.service-tickets.create') }}" class="button-primary">New service ticket</a>@endif
    </div>
    <form method="GET" class="surface mt-6 grid gap-3 p-4 md:grid-cols-7">
        <div class="md:col-span-2"><label class="form-label" for="search">Search</label><input class="form-input" id="search" name="search" value="{{ request('search') }}" placeholder="Ticket, customer, location, title"></div>
        <div><label class="form-label" for="status">Status</label><select class="form-input" id="status" name="status"><option value="">All</option>@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="priority">Priority</label><select class="form-input" id="priority" name="priority"><option value="">All</option>@foreach($priorities as $value=>$label)<option value="{{ $value }}" @selected(request('priority')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="source">Source</label><select class="form-input" id="source" name="source"><option value="">All</option>@foreach($sources as $value=>$label)<option value="{{ $value }}" @selected(request('source')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="assignee">Assignee</label><select class="form-input" id="assignee" name="assignee"><option value="">All</option>@foreach($memberships as $membership)<option value="{{ $membership->id }}" @selected((string)request('assignee')===(string)$membership->id)>{{ $membership->user->name }}</option>@endforeach</select></div>
        <div class="flex items-end"><button class="button-secondary w-full">Filter</button></div>
    </form>
    <div class="surface mt-6 divide-y divide-slate-200">
        @forelse($tickets as $ticket)
            <a href="{{ route('office.service-tickets.show', $ticket) }}" class="grid min-h-20 gap-2 p-5 hover:bg-slate-50 md:grid-cols-[170px_1fr_190px_120px] md:items-center">
                <span class="font-bold text-brand-blue">{{ $ticket->ticket_number }}</span>
                <span><strong class="block text-slate-950">{{ $ticket->title }}</strong><span class="text-sm text-slate-500">{{ $ticket->customer->display_name }} · {{ $ticket->serviceLocation->name }}</span></span>
                <span class="text-sm text-slate-600">{{ $ticket->visits_count }} {{ Str::plural('visit', $ticket->visits_count) }}</span>
                <span class="{{ in_array($ticket->priority, ['high','urgent']) ? 'status-priority' : ($ticket->status === 'on_hold' ? 'status-hold' : 'status-active') }}">{{ ucfirst(str_replace('_',' ',$ticket->status)) }} · {{ ucfirst($ticket->priority) }}</span>
            </a>
        @empty
            <div class="p-8 text-center"><h2 class="font-bold text-slate-900">No service tickets found</h2><p class="mt-2 text-sm text-slate-500">Adjust the filters or create the first ticket.</p></div>
        @endforelse
    </div>
    <div class="mt-5">{{ $tickets->links() }}</div>
</x-layouts.office>
