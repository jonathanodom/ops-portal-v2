<x-layouts.office title="Customers" width="workspace">
    @php($activeFilterCount = collect(['search', 'status', 'type'])->filter(fn ($key) => filled(request($key)))->count())
    <form method="GET" aria-label="Customer filters">
        <x-office.primary-toolbar title="Customers" description="Customer accounts, contacts, and service locations.">
            <x-slot:search>
                <label for="search" class="sr-only">Search customers</label>
                <input id="search" name="search" class="form-input" value="{{ request('search') }}" placeholder="Search name, phone, email, or address">
            </x-slot:search>
            <x-slot:viewSwitcher><x-office.customer-workspace-tabs /></x-slot:viewSwitcher>
            <x-slot:filters>
                <x-office.filter-panel :active-count="$activeFilterCount">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input"><option value="">All statuses</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
                        <div><label for="type" class="form-label">Customer type</label><select id="type" name="type" class="form-input"><option value="">All types</option>@foreach ($types as $value => $label)<option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>@endforeach</select></div>
                    </div>
                    <div class="mt-4 flex flex-wrap justify-end gap-2"><a href="{{ route('office.customers.index') }}" class="button-secondary">Clear all</a><button class="button-primary">Apply filters</button></div>
                </x-office.filter-panel>
            </x-slot:filters>
            @if ($activeMembership->hasCapability('customers.manage'))
                <x-slot:primaryAction><a href="{{ route('office.customers.create') }}" class="button-primary">Add customer</a></x-slot:primaryAction>
            @endif
            @if($activeFilterCount)
                <x-slot:chips>
                    <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Active filters</span>
                    @if(filled(request('search')))<x-office.filter-chip label="Search: {{ request('search') }}" :remove-url="route('office.customers.index', request()->except(['search', 'page']))" />@endif
                    @if(filled(request('status')))<x-office.filter-chip label="Status: {{ $statuses[request('status')] ?? Str::headline(request('status')) }}" :remove-url="route('office.customers.index', request()->except(['status', 'page']))" />@endif
                    @if(filled(request('type')))<x-office.filter-chip label="Type: {{ $types[request('type')] ?? Str::headline(request('type')) }}" :remove-url="route('office.customers.index', request()->except(['type', 'page']))" />@endif
                    <a href="{{ route('office.customers.index') }}" class="inline-flex min-h-9 items-center px-2 text-xs font-bold text-brand-blue underline">Clear all</a>
                </x-slot:chips>
            @endif
        </x-office.primary-toolbar>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table">
            <caption class="sr-only">Customer directory</caption>
            <thead><tr><th scope="col">Customer</th><th scope="col">Type</th><th scope="col">Preferred contact</th><th scope="col">Locations</th><th scope="col">Status</th><th scope="col" class="text-right">Action</th></tr></thead>
            <tbody>
                @forelse ($customers as $customer)
                    <tr>
                        <td>
                            <a href="{{ route('office.customers.show', $customer) }}" class="font-bold text-slate-950 hover:text-brand-blue">{{ $customer->display_name }}</a>
                            <p class="mt-0.5 text-xs text-slate-500">{{ $customer->email ?: $customer->phone ?: 'No account contact details' }}</p>
                        </td>
                        <td>{{ $types[$customer->type] }}</td>
                        <td>
                            <p class="font-semibold text-slate-900">{{ $customer->preferredContact?->name ?: 'Not assigned' }}</p>
                            @if($customer->preferredContact)<p class="mt-0.5 text-xs text-slate-500">{{ $customer->preferredContact->phone ?: $customer->preferredContact->email ?: 'No contact details' }}</p>@endif
                        </td>
                        <td>{{ $customer->service_locations_count }}</td>
                        <td><span class="{{ $customer->status === 'active' ? 'status-active' : ($customer->status === 'on_hold' ? 'status-hold' : 'status-inactive') }}">{{ $statuses[$customer->status] }}</span></td>
                        <td class="text-right"><a href="{{ route('office.customers.show', $customer) }}" class="inline-flex min-h-11 items-center font-bold text-brand-blue">Open<span class="sr-only"> {{ $customer->display_name }}</span></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-3"><x-office.state-panel title="No customers found" message="Clear filters or add the first customer." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @forelse ($customers as $customer)
            <a href="{{ route('office.customers.show', $customer) }}" class="office-mobile-card">
                <div class="flex items-start justify-between gap-3"><div><p class="font-bold text-slate-950">{{ $customer->display_name }}</p><p class="mt-1 text-sm text-slate-500">{{ $types[$customer->type] }}</p></div><span class="{{ $customer->status === 'active' ? 'status-active' : ($customer->status === 'on_hold' ? 'status-hold' : 'status-inactive') }}">{{ $statuses[$customer->status] }}</span></div>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2"><div><dt class="font-semibold text-slate-500">Preferred contact</dt><dd class="mt-0.5 text-slate-800">{{ $customer->preferredContact?->name ?: $customer->email ?: $customer->phone ?: 'Not provided' }}</dd></div><div><dt class="font-semibold text-slate-500">Locations</dt><dd class="mt-0.5 text-slate-800">{{ $customer->service_locations_count }}</dd></div></dl>
            </a>
        @empty
            <x-office.state-panel title="No customers found" message="Clear filters or add the first customer." />
        @endforelse
    </div>
    <div class="mt-5">{{ $customers->links() }}</div>
</x-layouts.office>
