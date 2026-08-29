<x-layouts.office title="Service locations" width="workspace">
    @php($activeFilterCount = collect(['search', 'status'])->filter(fn ($key) => filled(request($key)))->count())
    <form method="GET" aria-label="Location filters">
        <x-office.primary-toolbar title="Customers" description="Customer accounts, contacts, and service locations.">
            <x-slot:search>
                <label for="search" class="sr-only">Search service locations</label>
                <input id="search" name="search" class="form-input" value="{{ request('search') }}" placeholder="Search customer, location, city, or ZIP">
            </x-slot:search>
            <x-slot:viewSwitcher><x-office.customer-workspace-tabs /></x-slot:viewSwitcher>
            <x-slot:filters>
                <x-office.filter-panel :active-count="$activeFilterCount">
                    <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input"><option value="">All locations</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2"><a href="{{ route('office.locations.index') }}" class="button-secondary">Clear all</a><button class="button-primary">Apply filters</button></div>
                </x-office.filter-panel>
            </x-slot:filters>
            @if ($activeMembership->hasCapability('customers.manage'))
                <x-slot:primaryAction><a href="{{ route('office.customers.create') }}" class="button-primary">Add customer</a></x-slot:primaryAction>
            @endif
            @if($activeFilterCount)
                <x-slot:chips>
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>
                    @if(filled(request('search')))<x-office.filter-chip label="Search: {{ request('search') }}" :remove-url="route('office.locations.index', request()->except(['search', 'page']))" />@endif
                    @if(filled(request('status')))<x-office.filter-chip label="Status: {{ Str::headline(request('status')) }}" :remove-url="route('office.locations.index', request()->except(['status', 'page']))" />@endif
                    <a href="{{ route('office.locations.index') }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a>
                </x-slot:chips>
            @endif
        </x-office.primary-toolbar>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table">
            <caption class="sr-only">All customer service locations</caption>
            <thead><tr><th scope="col">Location</th><th scope="col">Customer</th><th scope="col">Address</th><th scope="col">Primary contact</th><th scope="col">Status</th><th scope="col" class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse($locations as $location)
                    <tr>
                        <td><a href="{{ route('office.locations.show', $location) }}" class="font-bold text-slate-950 hover:text-brand-blue">{{ $location->name }}</a>@if($location->is_primary)<p class="mt-0.5 text-xs font-semibold text-brand-blue">Primary location</p>@endif</td>
                        <td><a href="{{ route('office.customers.show', $location->customer) }}" class="font-semibold text-slate-900 hover:text-brand-blue">{{ $location->customer->display_name }}</a></td>
                        <td>{{ $location->formattedAddress() }}</td>
                        <td><p class="font-semibold text-slate-900">{{ $location->primaryContact?->name ?: 'Not assigned' }}</p>@if($location->primaryContact)<p class="mt-0.5 text-xs text-slate-500">{{ $location->primaryContact->phone ?: $location->primaryContact->email ?: 'No contact details' }}</p>@endif</td>
                        <td><span class="{{ $location->active ? 'status-active' : 'status-inactive' }}">{{ $location->active ? 'Active' : 'Inactive' }}</span></td>
                        <td class="text-right"><a href="{{ route('office.locations.show', $location) }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue">Open<span class="sr-only"> {{ $location->name }}</span></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-3"><x-office.state-panel title="No service locations found" message="Clear filters to broaden the location list." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @forelse($locations as $location)
            <a href="{{ route('office.locations.show', $location) }}" class="office-mobile-card">
                <div class="flex items-start justify-between gap-3"><div><p class="font-bold text-slate-950">{{ $location->name }}</p><p class="mt-1 text-sm font-semibold text-slate-600">{{ $location->customer->display_name }}</p></div><span class="{{ $location->active ? 'status-active' : 'status-inactive' }}">{{ $location->active ? 'Active' : 'Inactive' }}</span></div>
                <p class="mt-3 text-sm text-slate-700">{{ $location->formattedAddress() }}</p>
                <p class="mt-2 text-sm text-slate-500"><strong class="text-slate-700">Primary contact:</strong> {{ $location->primaryContact?->name ?: 'Not assigned' }}</p>
            </a>
        @empty
            <x-office.state-panel title="No service locations found" message="Clear filters to broaden the location list." />
        @endforelse
    </div>
    <div class="mt-5">{{ $locations->links() }}</div>
</x-layouts.office>
