<x-layouts.office title="NewDay Home" width="workspace">
    @php
        $dashboard = $home['portal'];
        $primaryAction = collect($dashboard['actions'])->firstWhere('is_primary', true);
        $secondaryActions = collect($dashboard['actions'])->reject(fn ($action) => $action['is_primary'] ?? false);
        $attentionCards = collect([
            $dashboard['attention']['unscheduled'] === null ? null : [
                'label' => 'Unscheduled Work',
                'value' => $dashboard['attention']['unscheduled']['total'],
                'detail' => $dashboard['attention']['unscheduled']['tickets'].' tickets · '.$dashboard['attention']['unscheduled']['visits'].' visits',
                'route' => route('office.dispatch.index'),
                'tone' => $dashboard['attention']['unscheduled']['total'] > 0 ? 'orange' : 'slate',
            ],
            $dashboard['attention']['awaiting_review'] === null ? null : [
                'label' => 'Awaiting Review',
                'value' => $dashboard['attention']['awaiting_review']['count'],
                'detail' => $dashboard['attention']['awaiting_review']['oldest_submitted_at']
                    ? 'Oldest '.$dashboard['attention']['awaiting_review']['oldest_submitted_at']->diffForHumans(short: true)
                    : 'No Closeouts awaiting review',
                'route' => route('office.closeout-reviews.index'),
                'tone' => $dashboard['attention']['awaiting_review']['count'] > 0 ? 'orange' : 'slate',
            ],
            $dashboard['attention']['ready_to_invoice'] === null ? null : [
                'label' => 'Ready to Invoice',
                'value' => $dashboard['attention']['ready_to_invoice'],
                'detail' => $dashboard['attention']['ready_to_invoice'] === 1 ? '1 approved handoff' : $dashboard['attention']['ready_to_invoice'].' approved handoffs',
                'route' => route('office.invoices.index', ['workspace' => 'ready_to_invoice']),
                'tone' => $dashboard['attention']['ready_to_invoice'] > 0 ? 'blue' : 'slate',
            ],
            $dashboard['attention']['overdue'] === null ? null : [
                'label' => 'Overdue Invoices',
                'value' => '$'.number_format($dashboard['attention']['overdue']['amount_cents'] / 100, 2),
                'detail' => $dashboard['attention']['overdue']['count'] === 1 ? '1 invoice' : $dashboard['attention']['overdue']['count'].' invoices',
                'route' => route('office.invoices.index', ['workspace' => 'overdue']),
                'tone' => $dashboard['attention']['overdue']['count'] > 0 ? 'red' : 'slate',
            ],
        ])->filter();
    @endphp

    <x-office.page-header
        title="NewDay Home"
        :eyebrow="$dashboard['local_date']->format('l, F j')"
        :description="$activeOrganization->name.' · Your command center for service operations, projects, billing, and work needing attention.'"
        data-dashboard-header
    >
        @if($primaryAction || count($home['quick_add']))
            <x-slot:actions>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    @if(count($home['quick_add']))
                        <details class="group relative w-full sm:w-auto" data-home-quick-add>
                            <summary class="button-secondary min-h-11 w-full cursor-pointer list-none sm:w-auto">+ Quick Add</summary>
                            <div class="absolute right-0 z-20 mt-2 w-64 max-w-[calc(100vw-2rem)] border border-slate-300 bg-white p-2 shadow-lg">
                                @foreach($home['quick_add'] as $action)
                                    <a class="flex min-h-11 items-center px-3 py-2 font-bold text-slate-800 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-brand-blue" href="{{ $action['route'] }}">{{ $action['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @if($primaryAction)<a class="button-primary w-full sm:w-auto" href="{{ route($primaryAction['route']) }}">{{ $primaryAction['label'] }}</a>@endif
                </div>
            </x-slot:actions>
        @endif
    </x-office.page-header>

    @if($home['search_visible'])
        <form method="GET" action="{{ route('office.search') }}" class="surface mt-6 p-4 sm:p-5" role="search" data-home-directory-search>
            <label class="form-label" for="home-directory-search">Search Customers, Contacts, and Service Locations</label>
            <div class="mt-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <input class="form-input" id="home-directory-search" name="q" minlength="2" placeholder="Name, phone, email, or address" autocomplete="off">
                <button class="button-primary w-full sm:w-auto">Search directory</button>
            </div>
        </form>
    @endif

    @if(count($home['launchers']))
        <section class="mt-6" aria-labelledby="home-apps-heading" data-home-apps>
            <div class="flex items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-blue">Workspaces</p><h2 id="home-apps-heading" class="mt-1 text-xl font-bold text-slate-950">Apps</h2></div></div>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                @foreach($home['launchers'] as $launcher)
                    <a class="surface group flex min-h-28 items-center justify-between gap-4 border-l-4 border-l-brand-blue p-5 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-blue" href="{{ $launcher['route'] }}">
                        <span><span class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">{{ $launcher['eyebrow'] }}</span><span class="mt-1 block text-lg font-bold text-slate-950">{{ $launcher['label'] }}</span><span class="mt-1 block text-sm text-slate-600">{{ $launcher['description'] }}</span></span>
                        <span class="text-xl font-bold text-brand-blue" aria-hidden="true">→</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if($attentionCards->isNotEmpty())
        <section class="mt-6" aria-labelledby="dashboard-attention-heading" data-dashboard-attention>
            <h2 id="dashboard-attention-heading" class="sr-only">Items needing attention</h2>
            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                @foreach($attentionCards as $card)
                    @php
                        $toneClasses = match($card['tone']) {
                            'orange' => 'border-l-brand-orange hover:border-brand-orange',
                            'blue' => 'border-l-brand-blue hover:border-brand-blue',
                            'red' => 'border-l-red-600 hover:border-red-500',
                            default => 'border-l-slate-300 hover:border-slate-400',
                        };
                    @endphp
                    <a href="{{ $card['route'] }}" class="surface {{ $toneClasses }} min-h-32 border-l-4 p-4 transition-colors hover:bg-slate-50 sm:min-h-36 sm:p-5" aria-label="{{ $card['label'] }}: {{ $card['value'] }}. {{ $card['detail'] }}">
                        <p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-600">{{ $card['label'] }}</p>
                        <p class="mt-3 text-2xl font-bold tracking-tight text-slate-950 sm:text-3xl">{{ $card['value'] }}</p>
                        <p class="mt-1 text-xs font-semibold text-slate-500 sm:text-sm">{{ $card['detail'] }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="surface mt-6 overflow-hidden" aria-labelledby="home-needs-attention-heading" data-home-attention-feed>
        <div class="border-b border-slate-200 p-5 sm:p-6"><p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-orange-dark">Cross-workspace priorities</p><h2 id="home-needs-attention-heading" class="mt-1 text-xl font-bold text-slate-950">Needs Attention</h2><p class="mt-1 text-sm text-slate-600">Open the authoritative workspace to take action.</p></div>
        @if($home['attention_items']->isEmpty())
            <div class="p-6"><p class="font-bold text-slate-900">No current attention items</p><p class="mt-1 text-sm text-slate-600">Overdue, blocked, review, billing, and upcoming work will appear here.</p></div>
        @else
            <ol class="divide-y divide-slate-200">
                @foreach($home['attention_items'] as $item)
                    @php $badgeClass = match($item['severity']) { 'critical' => 'status-danger', 'attention' => 'status-priority', default => 'status-active' }; @endphp
                    <li><a class="grid min-h-20 gap-3 p-4 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-blue sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-6" href="{{ $item['route'] }}"><span class="min-w-0"><span class="block font-bold text-slate-950">{{ $item['title'] }}</span><span class="mt-1 block text-sm text-slate-600">{{ $item['context'] }}</span><span class="mt-1 block text-xs font-bold uppercase tracking-wide text-slate-500">{{ ucfirst($item['domain']) }}</span></span><span class="{{ $badgeClass }} w-fit">{{ $item['badge'] }}</span></a></li>
                @endforeach
            </ol>
        @endif
    </section>

    @if($home['projects'] !== null)
        <section class="surface mt-6 overflow-hidden" aria-labelledby="home-projects-heading" data-home-projects>
            <div class="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6"><div><p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-blue">Projects workspace</p><h2 id="home-projects-heading" class="mt-1 text-xl font-bold text-slate-950">Projects</h2></div><a class="inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ route('office.projects.index') }}">Open Projects <span class="ml-1" aria-hidden="true">→</span></a></div>
            <dl class="grid grid-cols-2 divide-x divide-y divide-slate-200 sm:grid-cols-3 xl:grid-cols-5 xl:divide-y-0">
                @foreach(['active' => 'Active', 'due_today' => 'Due today', 'overdue' => 'Overdue tasks', 'blocked' => 'Blocked tasks', 'upcoming_milestones' => 'Upcoming milestones'] as $key => $label)
                    <div class="p-4 sm:p-5"><dt class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ $label }}</dt><dd class="mt-2 text-2xl font-bold {{ in_array($key, ['overdue','blocked'], true) && $home['projects']['counts'][$key] ? 'text-brand-orange-dark' : 'text-slate-950' }}">{{ $home['projects']['counts'][$key] }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    <div class="mt-6 grid items-start gap-6 xl:grid-cols-3">
        @if($dashboard['today'] !== null)
            <section class="surface overflow-hidden xl:col-span-2" aria-labelledby="dashboard-today-heading" data-dashboard-today>
                <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-blue">Field operations</p>
                        <h2 id="dashboard-today-heading" class="mt-1 text-xl font-bold text-slate-950">Today’s Visits</h2>
                    </div>
                    <dl class="grid grid-cols-3 gap-4 text-sm sm:gap-6">
                        <div><dt class="font-semibold text-slate-500">Total</dt><dd class="mt-1 text-xl font-bold text-slate-950">{{ $dashboard['today']['total'] }}</dd></div>
                        <div><dt class="font-semibold text-slate-500">In progress</dt><dd class="mt-1 text-xl font-bold text-brand-orange-dark">{{ $dashboard['today']['in_progress'] }}</dd></div>
                        <div><dt class="font-semibold text-slate-500">Remaining</dt><dd class="mt-1 text-xl font-bold text-brand-blue-dark">{{ $dashboard['today']['remaining'] }}</dd></div>
                    </dl>
                </div>

                @if($dashboard['today']['visits']->isEmpty())
                    <div class="p-6 text-center sm:p-10">
                        <p class="font-bold text-slate-900">No Visits scheduled today</p>
                        <p class="mt-1 text-sm text-slate-600">Check Dispatch for unscheduled work and future appointments.</p>
                    </div>
                @else
                    <ol class="divide-y divide-slate-200" aria-label="Today’s scheduled Visits">
                        @foreach($dashboard['today']['visits'] as $visit)
                            @php
                                $lead = $visit->assignments->firstWhere('is_lead', true)?->membership?->user
                                    ?? $visit->assignments->first()?->membership?->user;
                                $statusClass = match($visit->status) {
                                    'en_route', 'on_site' => 'status-active',
                                    'pending_closeout', 'returned_for_correction' => 'status-hold',
                                    'approved' => 'status-success',
                                    default => 'status-muted',
                                };
                            @endphp
                            <li>
                                <a href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}" class="grid min-h-20 gap-3 p-4 transition-colors hover:bg-blue-50/50 sm:grid-cols-[8rem_minmax(0,1fr)_auto] sm:items-center sm:px-6" aria-label="{{ $visit->scheduledStartLocal()?->format('g:i A') }}, {{ $visit->serviceTicket->customer->display_name }}, {{ $visit->serviceTicket->title }}, {{ Str::headline($visit->status) }}">
                                    <div>
                                        <p class="text-lg font-bold text-slate-950">{{ $visit->scheduledStartLocal()?->format('g:i A') }}</p>
                                        @if($visit->timezone !== $activeOrganization->timezone)<p class="text-xs font-semibold text-slate-500">{{ $visit->scheduledStartLocal()?->format('T') }}</p>@endif
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-slate-950">{{ $visit->serviceTicket->customer->display_name }}</p>
                                        <p class="mt-0.5 truncate text-sm text-slate-600">{{ $visit->serviceTicket->title }}</p>
                                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $visit->displayNumber() }} · {{ $lead?->name ?: 'Crew not assigned' }}</p>
                                    </div>
                                    <span class="{{ $statusClass }} w-fit">{{ Str::headline($visit->status) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
                <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                    <a href="{{ route('office.dispatch.index', ['date' => $dashboard['local_date']->toDateString()]) }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue-dark hover:text-brand-blue-deep">View Dispatch <span aria-hidden="true" class="ml-1">→</span></a>
                </div>
            </section>
        @endif

        @if($dashboard['billing'] !== null)
            <section class="surface order-3 overflow-hidden xl:order-none" aria-labelledby="dashboard-billing-heading" data-dashboard-billing>
                <div class="border-b border-slate-200 p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-blue">Financial workflow</p>
                    <h2 id="dashboard-billing-heading" class="mt-1 text-xl font-bold text-slate-950">Billing &amp; Collections</h2>
                </div>
                <dl class="divide-y divide-slate-200 px-5 sm:px-6">
                    @if($dashboard['billing']['ready_to_invoice'] !== null)
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Ready to invoice</dt><dd class="font-bold text-slate-950">{{ $dashboard['billing']['ready_to_invoice'] }}</dd></div>
                    @endif
                    @if($dashboard['billing']['invoices'] !== null)
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Draft</dt><dd class="font-bold text-slate-950">{{ $dashboard['billing']['invoices']['draft_count'] }}</dd></div>
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Ready for review</dt><dd class="font-bold text-slate-950">{{ $dashboard['billing']['invoices']['ready_for_review_count'] }}</dd></div>
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Issued / open</dt><dd class="font-bold text-slate-950">{{ $dashboard['billing']['invoices']['issued_open_count'] }}</dd></div>
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Open A/R</dt><dd class="text-lg font-bold text-slate-950">${{ number_format($dashboard['billing']['invoices']['open_ar_cents'] / 100, 2) }}</dd></div>
                        <div class="flex min-h-12 items-center justify-between gap-4 py-3"><dt class="font-semibold text-slate-600">Overdue</dt><dd class="text-lg font-bold text-red-700">${{ number_format($dashboard['billing']['invoices']['overdue_cents'] / 100, 2) }}</dd></div>
                    @endif
                </dl>
                @if($dashboard['billing']['invoices'] !== null && $dashboard['billing']['invoices']['oldest_overdue']->isNotEmpty())
                    <div class="border-t border-slate-200 bg-red-50/50 p-5 sm:p-6">
                        <h3 class="text-xs font-bold uppercase tracking-[0.1em] text-red-800">Oldest overdue</h3>
                        <ul class="mt-3 space-y-3">
                            @foreach($dashboard['billing']['invoices']['oldest_overdue'] as $invoice)
                                <li><a href="{{ route('office.invoices.show', $invoice) }}" class="flex min-h-11 items-center justify-between gap-3 rounded-lg px-2 py-1 hover:bg-white"><span class="min-w-0"><span class="block truncate font-bold text-slate-950">{{ $invoice->invoice_number }}</span><span class="block truncate text-xs text-slate-600">{{ $invoice->customer->display_name }} · {{ $invoice->due_on->diffForHumans(short: true) }}</span></span><span class="shrink-0 font-bold text-red-700">${{ number_format($invoice->dashboard_balance_cents / 100, 2) }}</span></a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6">
                    <a href="{{ $dashboard['visibility']['invoices'] ? route('office.invoices.index') : route('office.billing-handoffs.index') }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue-dark hover:text-brand-blue-deep">View Billing <span aria-hidden="true" class="ml-1">→</span></a>
                </div>
            </section>
        @endif

        @if($dashboard['follow_up'] !== null)
            <section class="surface order-2 overflow-hidden xl:order-none xl:col-span-2" aria-labelledby="dashboard-follow-up-heading" data-dashboard-follow-up>
                <div class="border-b border-slate-200 p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-orange-dark">Exceptions</p>
                    <h2 id="dashboard-follow-up-heading" class="mt-1 text-xl font-bold text-slate-950">Needs Follow-Up</h2>
                </div>
                @if($dashboard['follow_up']->isEmpty())
                    <div class="p-6"><p class="font-bold text-slate-900">No current follow-up exceptions</p><p class="mt-1 text-sm text-slate-600">Urgent unplanned work, callbacks, warranty work, and returned Closeouts will appear here.</p></div>
                @else
                    <ul class="divide-y divide-slate-200">
                        @foreach($dashboard['follow_up'] as $item)
                            <li><a href="{{ route('office.service-tickets.show', $item['ticket']) }}" class="flex min-h-20 flex-col justify-center gap-2 p-4 transition-colors hover:bg-orange-50/50 sm:flex-row sm:items-center sm:justify-between sm:px-6"><span class="min-w-0"><span class="block font-bold text-slate-950">{{ $item['ticket']->ticket_number }} · {{ $item['ticket']->title }}</span><span class="mt-0.5 block truncate text-sm text-slate-600">{{ $item['ticket']->customer->display_name }}</span></span><span class="flex flex-wrap gap-1.5">@foreach($item['labels'] as $label)<span class="status-priority">{{ $label }}</span>@endforeach</span></a></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if($dashboard['health'] !== null)
            <section class="surface order-4 overflow-hidden xl:order-none" aria-labelledby="dashboard-health-heading" data-dashboard-health>
                <div class="border-b border-slate-200 p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[0.1em] text-brand-blue">Diagnostics</p>
                    <h2 id="dashboard-health-heading" class="mt-1 text-xl font-bold text-slate-950">System Health</h2>
                </div>
                <div class="p-5 sm:p-6">
                    @if($dashboard['health']['open_incidents'] === 0 && $dashboard['health']['failed_jobs'] === 0)
                        <span class="status-success">All systems normal</span>
                        <p class="mt-3 text-sm leading-6 text-slate-600">No persisted incidents or failed jobs need attention.</p>
                    @else
                        <dl class="space-y-3">
                            <div class="flex items-center justify-between"><dt class="font-semibold text-slate-600">Open incidents</dt><dd class="font-bold text-slate-950">{{ $dashboard['health']['open_incidents'] }}</dd></div>
                            <div class="flex items-center justify-between"><dt class="font-semibold text-slate-600">Critical / error</dt><dd class="font-bold text-red-700">{{ $dashboard['health']['high_incidents'] }}</dd></div>
                            <div class="flex items-center justify-between"><dt class="font-semibold text-slate-600">Failed jobs</dt><dd class="font-bold text-slate-950">{{ $dashboard['health']['failed_jobs'] }}</dd></div>
                        </dl>
                    @endif
                </div>
                <div class="border-t border-slate-200 bg-slate-50 px-5 py-3 sm:px-6"><a href="{{ route('office.operations.health') }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue-dark hover:text-brand-blue-deep">View Health <span aria-hidden="true" class="ml-1">→</span></a></div>
            </section>
        @endif
    </div>

    @if($secondaryActions->isNotEmpty())
        <section class="surface mt-6 p-5 sm:p-6" aria-labelledby="dashboard-actions-heading" data-dashboard-actions>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div><h2 id="dashboard-actions-heading" class="text-lg font-bold text-slate-950">Quick actions</h2><p class="mt-1 text-sm text-slate-600">Open the authoritative workspace for the next task.</p></div>
                <div class="grid gap-2 sm:flex sm:flex-wrap sm:justify-end">@foreach($secondaryActions as $action)<a href="{{ route($action['route']) }}" class="button-secondary w-full sm:w-auto">{{ $action['label'] }}</a>@endforeach</div>
            </div>
        </section>
    @endif
</x-layouts.office>
