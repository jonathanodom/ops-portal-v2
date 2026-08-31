<x-layouts.office title="Dispatch" width="workspace">
    @php
        $today = now($activeOrganization->timezone)->startOfDay();
        $baseQuery = array_merge($filterQuery, [
            'date' => $date->format('Y-m-d'),
            'calendar_month' => $calendarMonth->format('Y-m'),
        ]);
        $dispatchQuery = fn (array $changes = []) => array_merge($baseQuery, $changes);
        $dispatchFilterLabels = [];
        if (filled(request('assignee'))) $dispatchFilterLabels['assignee'] = 'Assignee: '.($memberships->firstWhere('id', (int) request('assignee'))?->user?->name ?? request('assignee'));
        foreach (['status' => 'Status', 'priority' => 'Priority'] as $key => $label) if (filled(request($key))) $dispatchFilterLabels[$key] = $label.': '.Str::headline(request($key));
    @endphp

    <form method="GET" aria-label="Dispatch filters">
        <input type="hidden" name="date" value="{{ $date->format('Y-m-d') }}">
        <input type="hidden" name="calendar_month" value="{{ $calendarMonth->format('Y-m') }}">
        <x-office.primary-toolbar title="Dispatch" :description="$date->format('l, F j, Y').' · '.$activeOrganization->timezone" eyebrow="Control plane">
            <x-slot:filters><x-office.filter-panel :active-count="count($dispatchFilterLabels)"><div class="grid gap-3 sm:grid-cols-3"><div><label class="form-label" for="assignee">Assignee</label><select class="form-input" id="assignee" name="assignee"><option value="">All</option>@foreach($memberships as $membership)<option value="{{ $membership->id }}" @selected((string) request('assignee') === (string) $membership->id)>{{ $membership->user->name }}</option>@endforeach</select></div><div><label class="form-label" for="status">Status</label><select class="form-input" id="status" name="status"><option value="">All</option>@foreach($visitStatuses as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="priority">Priority</label><select class="form-input" id="priority" name="priority"><option value="">All</option>@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>@endforeach</select></div></div><div class="mt-4 flex flex-wrap justify-end gap-2"><a class="button-secondary" href="{{ route('office.dispatch.index', ['date' => $date->format('Y-m-d'), 'calendar_month' => $calendarMonth->format('Y-m')]) }}">Clear all</a><button class="button-primary">Apply filters</button></div></x-office.filter-panel></x-slot:filters>
        @if($activeMembership->hasCapability('dispatch.manage'))
            <x-slot:primaryAction><a class="button-primary" href="{{ route('office.service-tickets.create') }}">New service ticket</a></x-slot:primaryAction>
        @endif
            @if($dispatchFilterLabels)
                <x-slot:chips><span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>@foreach($dispatchFilterLabels as $key => $label)<x-office.filter-chip :label="$label" :remove-url="route('office.dispatch.index', array_merge($baseQuery, request()->except([$key])))" />@endforeach<a href="{{ route('office.dispatch.index', ['date' => $date->format('Y-m-d'), 'calendar_month' => $calendarMonth->format('Y-m')]) }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a></x-slot:chips>
            @endif
        </x-office.primary-toolbar>
    </form>

    <section class="mt-6" aria-labelledby="dispatch-date-strip-heading">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="dispatch-date-strip-heading" class="text-lg font-bold">Five-day workload</h2>
                <p class="text-sm text-slate-500">Browse one day at a time.</p>
            </div>
            <div class="flex items-center gap-2" aria-label="Dispatch date navigation">
                <a class="button-secondary min-h-11 min-w-11 px-3" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $date->subDay()->format('Y-m-d')])) }}" aria-label="Previous day">&larr;</a>
                <a class="button-secondary min-h-11" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $today->format('Y-m-d')])) }}">Today</a>
                <a class="button-secondary min-h-11 min-w-11 px-3" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $date->addDay()->format('Y-m-d')])) }}" aria-label="Next day">&rarr;</a>
            </div>
        </div>

        <div class="grid grid-cols-5 gap-1.5 sm:gap-2" aria-label="Five day workload" data-dispatch-date-strip>
            @foreach($strip as $day)
                <a href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $day['date']->format('Y-m-d')])) }}" class="surface min-h-20 px-1 py-2 text-center focus-visible:z-10 sm:p-3 {{ $day['date']->isSameDay($date) ? 'border-brand-blue bg-blue-50' : '' }}" @if($day['date']->isSameDay($date)) aria-current="date" @endif>
                    <span class="block text-[11px] font-bold uppercase text-slate-500 sm:text-xs">{{ $day['date']->format('D') }}</span>
                    <span class="mt-1 block font-bold">{{ $day['date']->format('j') }}</span>
                    <span class="mt-1 block text-[11px] text-slate-500 sm:text-xs">{{ $day['count'] }} {{ $day['count'] === 1 ? 'visit' : 'visits' }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
        <section>
            <h2 class="text-lg font-bold">Scheduled</h2>
            <div class="surface mt-3 divide-y divide-slate-200">
                @forelse($visits as $visit)
                    <a href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}" class="grid min-h-20 gap-2 p-4 hover:bg-slate-50 sm:grid-cols-[140px_1fr_180px] sm:items-center">
                        <strong class="text-brand-blue">{{ $visit->scheduledStartLocal()->format('g:i A') }}@if($visit->timezone !== $activeOrganization->timezone)<span class="block text-xs text-slate-500">{{ $visit->scheduledStartLocal()->format('T') }}</span>@endif</strong>
                        <span><strong class="block">{{ $visit->serviceTicket->ticket_number }} · {{ $visit->serviceTicket->title }}</strong><span class="text-sm text-slate-500">{{ $visit->serviceTicket->customer->display_name }} · {{ $visit->serviceLocation->name }}</span><span class="mt-1 block"><span class="{{ $visit->confirmationState(now(), $activeOrganization->timezone) === 'confirmed' ? 'status-success' : ($visit->confirmationState(now(), $activeOrganization->timezone) === 'needs_confirmation' ? 'status-priority' : 'status-muted') }}">{{ $visit->confirmationLabel(now(), $activeOrganization->timezone) }}</span></span></span>
                        <span class="text-sm text-slate-600">{{ $visit->assignments->map(fn ($assignment) => ($assignment->is_lead ? 'Lead: ' : '').$assignment->membership->user->name)->join(', ') }}</span>
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">No scheduled visits for this day.</div>
                @endforelse
            </div>
        </section>

        <section>
            <h2 class="text-lg font-bold">Unscheduled backlog</h2>
            <div class="surface mt-3 divide-y divide-slate-200">
                @foreach($ticketBacklog as $ticket)
                    <a href="{{ route('office.service-tickets.show', $ticket) }}" class="block min-h-20 p-4 hover:bg-slate-50"><strong class="block text-brand-orange">{{ $ticket->ticket_number }} · No visit</strong><span class="mt-1 block font-semibold">{{ $ticket->title }}</span><span class="mt-1 block text-sm text-slate-500">{{ $ticket->customer->display_name }}</span></a>
                @endforeach
                @forelse($backlog as $visit)
                    <a href="{{ route('office.visits.edit', $visit) }}" class="block min-h-20 p-4 hover:bg-slate-50"><strong class="block text-brand-blue">{{ $visit->serviceTicket->ticket_number }}</strong><span class="mt-1 block font-semibold">{{ $visit->serviceTicket->title }}</span><span class="mt-1 block text-sm text-slate-500">{{ $visit->serviceTicket->customer->display_name }}</span></a>
                @empty
                    @if($ticketBacklog->isEmpty())<div class="p-6 text-sm text-slate-500">No unscheduled work.</div>@endif
                @endforelse
            </div>
        </section>
    </div>

    <section class="mt-10" aria-labelledby="dispatch-calendar-heading" data-dispatch-calendar>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 id="dispatch-calendar-heading" class="text-xl font-bold">Dispatch calendar</h2><p class="mt-1 text-sm text-slate-500">Scheduled workload for {{ $calendarMonth->format('F Y') }}.</p></div>
            <div class="flex items-center gap-2" aria-label="Calendar month navigation">
                <a class="button-secondary min-h-11 min-w-11 px-3" href="{{ route('office.dispatch.index', $dispatchQuery(['calendar_month' => $calendarMonth->subMonth()->format('Y-m')])) }}" aria-label="Previous month">&larr;</a>
                <span class="min-w-36 text-center font-bold" aria-live="polite">{{ $calendarMonth->format('F Y') }}</span>
                <a class="button-secondary min-h-11 min-w-11 px-3" href="{{ route('office.dispatch.index', $dispatchQuery(['calendar_month' => $calendarMonth->addMonth()->format('Y-m')])) }}" aria-label="Next month">&rarr;</a>
            </div>
        </div>

        <div class="surface mt-4 hidden overflow-hidden lg:block" data-dispatch-calendar-grid>
            <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50 text-center text-xs font-bold uppercase tracking-wide text-slate-500">
                @foreach(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday)<div class="px-2 py-3">{{ $weekday }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @foreach($calendarDays as $day)
                    <div class="min-h-44 border-b border-r border-slate-200 p-2 {{ $day['in_month'] ? 'bg-white' : 'bg-slate-50 text-slate-600' }} {{ $day['date']->isSameDay($date) ? 'ring-2 ring-inset ring-brand-blue' : '' }}">
                        <div class="flex items-center justify-between gap-2">
                            <a class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md font-bold hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue {{ $day['date']->isSameDay($today) ? 'bg-brand-blue-dark text-white hover:bg-brand-blue-deep' : '' }}" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $day['date']->format('Y-m-d')])) }}" @if($day['date']->isSameDay($date)) aria-current="date" @endif aria-label="Open dispatch for {{ $day['date']->format('F j, Y') }}">{{ $day['date']->format('j') }}</a>
                            @if($day['count'] > 0)<span class="text-xs font-semibold text-slate-500">{{ $day['count'] }}</span>@endif
                        </div>
                        <div class="mt-1 space-y-1">
                            @foreach($day['visits'] as $visit)
                                <a href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}" class="block rounded border border-slate-200 bg-white p-1.5 text-xs text-slate-700 hover:border-brand-blue hover:bg-blue-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-blue"><span class="block truncate font-bold text-brand-blue">{{ $visit->scheduledStartLocal()->format('g:i A') }}@if($visit->timezone !== $activeOrganization->timezone) {{ $visit->scheduledStartLocal()->format('T') }}@endif · {{ $visit->serviceTicket->ticket_number }}</span><span class="block truncate">{{ $visit->serviceTicket->customer->display_name }} · {{ $visit->serviceLocation->name }}</span><span class="mt-1 block font-semibold {{ $visit->confirmationState(now(), $activeOrganization->timezone) === 'needs_confirmation' ? 'text-brand-orange' : 'text-slate-500' }}">{{ $visit->confirmationLabel(now(), $activeOrganization->timezone) }}</span></a>
                            @endforeach
                            @if($day['overflow'] > 0)<a class="block min-h-11 py-2 text-center text-xs font-bold text-brand-blue hover:underline" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $day['date']->format('Y-m-d')])) }}">+{{ $day['overflow'] }} more</a>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-4 space-y-4 lg:hidden" data-dispatch-calendar-agenda>
            @forelse($agenda as $agendaDate => $agendaVisits)
                @php($agendaDay = \Carbon\CarbonImmutable::parse($agendaDate, $activeOrganization->timezone))
                <section class="surface overflow-hidden" aria-labelledby="agenda-{{ $agendaDate }}">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2"><h3 id="agenda-{{ $agendaDate }}" class="font-bold">{{ $agendaDay->format('l, F j') }}</h3><a class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue" href="{{ route('office.dispatch.index', $dispatchQuery(['date' => $agendaDate])) }}">Open day</a></div>
                    <div class="divide-y divide-slate-200">
                        @foreach($agendaVisits as $visit)
                            <a href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}" class="block min-h-20 p-4 hover:bg-slate-50"><span class="font-bold text-brand-blue">{{ $visit->scheduledStartLocal()->format('g:i A') }}@if($visit->timezone !== $activeOrganization->timezone) {{ $visit->scheduledStartLocal()->format('T') }}@endif · {{ $visit->serviceTicket->ticket_number }}</span><span class="mt-1 block font-semibold">{{ $visit->serviceTicket->title }}</span><span class="mt-1 block text-sm text-slate-500">{{ $visit->serviceTicket->customer->display_name }} · {{ $visit->serviceLocation->name }}</span><span class="mt-2 {{ $visit->confirmationState(now(), $activeOrganization->timezone) === 'confirmed' ? 'status-success' : ($visit->confirmationState(now(), $activeOrganization->timezone) === 'needs_confirmation' ? 'status-priority' : 'status-muted') }}">{{ $visit->confirmationLabel(now(), $activeOrganization->timezone) }}</span></a>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="surface p-8 text-center text-sm text-slate-500">No scheduled visits match these filters in {{ $calendarMonth->format('F Y') }}.</div>
            @endforelse
        </div>
    </section>
</x-layouts.office>
