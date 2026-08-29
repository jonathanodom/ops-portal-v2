<x-layouts.office title="Opportunities" width="workspace">
    @if(session('status'))<x-office.alert>{{ session('status') }}</x-office.alert>@endif
    @php($activeFilterCount = collect(['search', 'stage', 'owner', 'priority'])->filter(fn ($key) => filled(request($key)))->count())
    <form method="GET" aria-label="Opportunity filters">
        <input type="hidden" name="view" value="{{ $viewMode }}">
        <x-office.primary-toolbar title="Opportunities" eyebrow="Commercial" description="Qualify work and keep the next action visible.">
            <x-slot:search><label class="sr-only" for="opportunity-search">Search opportunities</label><input class="form-input" id="opportunity-search" name="search" value="{{ request('search') }}" placeholder="Search number, title, customer, or site"></x-slot:search>
            <x-slot:viewSwitcher>
                <x-office.view-switcher aria-label="Opportunity view">
                    <a @if($viewMode==='kanban') aria-current="page" @endif href="{{ request()->fullUrlWithQuery(['view'=>'kanban']) }}">Kanban</a>
                    <a @if($viewMode==='list') aria-current="page" @endif href="{{ request()->fullUrlWithQuery(['view'=>'list']) }}">List</a>
                </x-office.view-switcher>
            </x-slot:viewSwitcher>
            <x-slot:filters>
                <x-office.filter-panel :active-count="$activeFilterCount">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label class="form-label" for="stage-filter">Stage</label><select class="form-input" id="stage-filter" name="stage"><option value="">All stages</option>@foreach($stages as $stage)<option value="{{ $stage->semantic_kind }}" @selected(request('stage')===$stage->semantic_kind)>{{ $stage->name }}</option>@endforeach</select></div>
                        <div><label class="form-label" for="owner-filter">Owner</label><select class="form-input" id="owner-filter" name="owner"><option value="">All owners</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string)request('owner')===(string)$member->id)>{{ $member->name }}</option>@endforeach</select></div>
                        <div><label class="form-label" for="priority-filter">Priority</label><select class="form-input" id="priority-filter" name="priority"><option value="">All priorities</option>@foreach(App\Domain\Commercial\OpportunityWorkflow::PRIORITIES as $priority)<option value="{{ $priority }}" @selected(request('priority')===$priority)>{{ str($priority)->headline() }}</option>@endforeach</select></div>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2"><a class="button-secondary" href="{{ route('office.opportunities.index',['view'=>$viewMode]) }}">Clear all</a><button class="button-primary">Apply filters</button></div>
                </x-office.filter-panel>
            </x-slot:filters>
            @can('create', [App\Models\Opportunity::class, $organization])
                <x-slot:primaryAction><a class="button-primary" href="{{ route('office.opportunities.create') }}">New opportunity</a></x-slot:primaryAction>
            @endcan
            @if($activeFilterCount)
                <x-slot:chips>
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>
                    @if(filled(request('search')))<x-office.filter-chip label="Search: {{ request('search') }}" :remove-url="route('office.opportunities.index', request()->except(['search', 'page']))" />@endif
                    @if(filled(request('stage')))<x-office.filter-chip label="Stage: {{ Str::headline(request('stage')) }}" :remove-url="route('office.opportunities.index', request()->except(['stage', 'page']))" />@endif
                    @if(filled(request('owner')))<x-office.filter-chip label="Owner: {{ optional($members->firstWhere('id', (int) request('owner')))->name ?? 'Selected' }}" :remove-url="route('office.opportunities.index', request()->except(['owner', 'page']))" />@endif
                    @if(filled(request('priority')))<x-office.filter-chip label="Priority: {{ Str::headline(request('priority')) }}" :remove-url="route('office.opportunities.index', request()->except(['priority', 'page']))" />@endif
                    <a href="{{ route('office.opportunities.index', ['view' => $viewMode]) }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a>
                </x-slot:chips>
            @endif
        </x-office.primary-toolbar>
    </form>
    @if($opportunities->isEmpty())
        <x-office.state-panel class="mt-5" title="No matching opportunities" message="Create the first Opportunity or adjust the filters." />
    @elseif($viewMode==='kanban')
        <div class="mt-6 overflow-x-auto pb-3" tabindex="0" aria-label="Opportunity Kanban board">
            <div class="grid min-w-[1200px] grid-cols-6 gap-4">
                @foreach($stages as $stage)
                    @php($cards=$opportunities->where('stage_id',$stage->id))
                    <section class="rounded-lg border border-slate-200 bg-slate-50" aria-labelledby="stage-{{ $stage->id }}"><header class="border-b border-slate-200 p-3"><h2 id="stage-{{ $stage->id }}" class="font-bold">{{ $stage->name }}</h2><p class="mt-1 text-xs text-slate-600">{{ $cards->count() }} · ${{ number_format($cards->sum('estimated_value_cents')/100,2) }}</p></header><div class="space-y-3 p-3">@forelse($cards as $opportunity)<a href="{{ route('office.opportunities.show',$opportunity) }}" class="block rounded-lg border border-slate-200 bg-white p-3 shadow-sm hover:border-brand-blue focus:outline-none focus:ring-2 focus:ring-brand-blue"><p class="text-xs font-bold text-brand-blue-dark">{{ $opportunity->opportunity_number }}</p><h3 class="mt-1 font-bold text-slate-950">{{ $opportunity->title }}</h3><p class="mt-2 text-sm text-slate-700">{{ $opportunity->customer->display_name }}</p><p class="text-xs text-slate-500">{{ $opportunity->serviceLocation?->name ?? 'Customer-wide' }}</p><dl class="mt-3 space-y-1 text-xs"><div class="flex justify-between gap-2"><dt>Value</dt><dd class="font-bold">${{ number_format($opportunity->estimated_value_cents/100,2) }}</dd></div><div class="flex justify-between gap-2"><dt>Quote / proposal</dt><dd>Not started</dd></div><div class="flex justify-between gap-2"><dt>Latest activity</dt><dd>{{ optional($opportunity->activities_max_occurred_at ?? $opportunity->updated_at)->format('M j') }}</dd></div></dl></a>@empty<p class="p-2 text-sm text-slate-500">No opportunities</p>@endforelse</div></section>
                @endforeach
            </div>
        </div>
    @else
        <div class="office-table-wrap"><table class="office-data-table"><thead><tr><th>Opportunity</th><th>Customer / site</th><th>Stage</th><th>Owner</th><th class="text-right">Estimate</th><th><span class="sr-only">Open</span></th></tr></thead><tbody>@foreach($opportunities as $opportunity)<tr><td><strong>{{ $opportunity->title }}</strong><span class="block text-xs text-slate-500">{{ $opportunity->opportunity_number }}</span></td><td>{{ $opportunity->customer->display_name }}<span class="block text-xs text-slate-500">{{ $opportunity->serviceLocation?->name ?? 'Customer-wide' }}</span></td><td>{{ $opportunity->stage->name }}</td><td>{{ $opportunity->owner?->name ?? 'Unassigned' }}</td><td class="text-right font-semibold">${{ number_format($opportunity->estimated_value_cents/100,2) }}</td><td class="text-right"><a class="button-secondary" href="{{ route('office.opportunities.show',$opportunity) }}">Open</a></td></tr>@endforeach</tbody></table></div>
        <div class="office-mobile-list">@foreach($opportunities as $opportunity)<article class="office-mobile-card"><div class="flex justify-between gap-3"><div><p class="text-xs font-bold text-brand-blue-dark">{{ $opportunity->opportunity_number }}</p><h2 class="mt-1 font-bold">{{ $opportunity->title }}</h2><p class="mt-1 text-sm text-slate-600">{{ $opportunity->customer->display_name }} · {{ $opportunity->stage->name }}</p></div><strong>${{ number_format($opportunity->estimated_value_cents/100,2) }}</strong></div><a class="button-secondary mt-4 w-full" href="{{ route('office.opportunities.show',$opportunity) }}">Open opportunity</a></article>@endforeach</div>
        <div class="mt-5">{{ $opportunities->links() }}</div>
    @endif
</x-layouts.office>
