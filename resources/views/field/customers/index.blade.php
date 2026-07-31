<x-layouts.field title="Customers">
    <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Field directory</p>
    <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Customers & locations</h1>
    <p class="mt-2 text-sm text-slate-600">Active operational details for {{ $activeOrganization->name }}.</p>
    <form method="GET" class="surface mt-5 p-4">
        <label for="search" class="form-label">Search</label>
        <div class="flex flex-col gap-3 sm:flex-row">
            <input id="search" name="search" value="{{ $search }}" class="form-input flex-1" placeholder="Customer, phone, email, or address" enterkeyhint="search">
            <button class="button-primary">Search</button>
        </div>
    </form>
    <section class="mt-6">
        <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Customers</h2>
        <div class="mt-3 space-y-3">
            @forelse($customers as $customer)
                <a href="{{ route('field.customers.show', $customer) }}" class="surface block min-h-20 p-4 hover:border-brand-blue">
                    <p class="font-bold text-slate-950">{{ $customer->display_name }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $customer->preferredContact?->name ?: ($customer->phone ?: 'No preferred contact') }}</p>
                    <p class="mt-2 text-xs font-bold text-brand-blue">{{ $customer->service_locations_count }} {{ Str::plural('location', $customer->service_locations_count) }} →</p>
                </a>
            @empty <div class="surface p-5 text-sm text-slate-500">No active customers match this search.</div> @endforelse
        </div>
        <div class="mt-4">{{ $customers->links() }}</div>
    </section>
    <section class="mt-7">
        <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Locations</h2>
        <div class="mt-3 space-y-3">
            @forelse($locations as $location)
                <a href="{{ route('field.locations.show', $location) }}" class="surface block min-h-20 p-4 hover:border-brand-blue">
                    <p class="font-bold text-slate-950">{{ $location->name }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $location->customer->display_name }}</p>
                    <p class="mt-2 text-sm text-slate-500">{{ $location->formattedAddress() }}</p>
                </a>
            @empty <div class="surface p-5 text-sm text-slate-500">No active locations match this search.</div> @endforelse
        </div>
        <div class="mt-4">{{ $locations->links() }}</div>
    </section>
</x-layouts.field>
