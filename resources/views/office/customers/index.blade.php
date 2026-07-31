<x-layouts.office title="Customers">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-sm font-bold text-brand-blue">Customer directory</p>
            <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Customers</h1>
            <p class="mt-2 text-slate-600">Customer, contact, and service-location context for field work.</p>
        </div>
        @if ($activeMembership->hasCapability('customers.manage'))
            <a href="{{ route('office.customers.create') }}" class="button-primary">Add customer</a>
        @endif
    </div>

    <form method="GET" class="surface mt-6 grid gap-4 p-4 md:grid-cols-[minmax(0,1fr)_180px_200px_auto]">
        <div>
            <label for="search" class="form-label">Search directory</label>
            <input id="search" name="search" class="form-input" value="{{ request('search') }}" placeholder="Name, phone, email, or address">
        </div>
        <div>
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-input">
                <option value="">All statuses</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="type" class="form-label">Customer type</label>
            <select id="type" name="type" class="form-input">
                <option value="">All types</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="button-secondary self-end">Search</button>
    </form>

    <div class="surface mt-6 overflow-hidden">
        <div class="divide-y divide-slate-200">
            @forelse ($customers as $customer)
                <a href="{{ route('office.customers.show', $customer) }}" class="grid min-h-20 gap-2 px-5 py-4 hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_180px_160px] sm:items-center">
                    <div>
                        <p class="font-bold text-slate-950">{{ $customer->display_name }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $customer->email ?: $customer->phone ?: 'No primary contact details' }}</p>
                    </div>
                    <p class="text-sm text-slate-600">{{ $customer->service_locations_count }} {{ Str::plural('location', $customer->service_locations_count) }}</p>
                    <span class="{{ $customer->status === 'active' ? 'status-active' : ($customer->status === 'on_hold' ? 'status-hold' : 'status-inactive') }} w-fit">{{ $statuses[$customer->status] }}</span>
                </a>
            @empty
                <div class="p-10 text-center">
                    <p class="font-bold text-slate-900">No customers found</p>
                    <p class="mt-2 text-sm text-slate-500">Try a broader search or add the first customer.</p>
                </div>
            @endforelse
        </div>
    </div>
    <div class="mt-5">{{ $customers->links() }}</div>
</x-layouts.office>
