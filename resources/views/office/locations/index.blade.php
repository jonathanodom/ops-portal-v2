<x-layouts.office title="Service locations">
    <div>
        <p class="text-sm font-bold text-brand-blue">Customer directory</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-slate-950">Service locations</h1>
        <p class="mt-2 text-slate-600">All customer sites in {{ $activeOrganization->name }}.</p>
    </div>
    <form method="GET" class="surface mt-6 grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_180px_auto] sm:items-end">
        <div class="flex-1"><label for="search" class="form-label">Search locations</label><input id="search" name="search" class="form-input" value="{{ request('search') }}" placeholder="Customer, location, city, or ZIP"></div>
        <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input"><option value="">All locations</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
        <button class="button-secondary">Search</button>
    </form>
    <div class="surface mt-6 divide-y divide-slate-200 overflow-hidden">
        @forelse($locations as $location)
            <a href="{{ route('office.locations.show', $location) }}" class="grid min-h-20 gap-2 px-5 py-4 hover:bg-slate-50 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_100px] sm:items-center">
                <div><p class="font-bold text-slate-950">{{ $location->name }}</p><p class="mt-1 text-sm text-slate-500">{{ $location->customer->display_name }}</p></div>
                <p class="text-sm text-slate-600">{{ $location->formattedAddress() }}</p>
                <span class="{{ $location->active ? 'status-active' : 'status-inactive' }} w-fit">{{ $location->active ? 'Active' : 'Inactive' }}</span>
            </a>
        @empty <div class="p-10 text-center text-sm text-slate-500">No service locations found.</div> @endforelse
    </div>
    <div class="mt-5">{{ $locations->links() }}</div>
</x-layouts.office>
