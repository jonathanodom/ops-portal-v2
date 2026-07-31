<x-layouts.office :title="$location->name">
    @if (session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('office.customers.show', $location->customer) }}" class="text-sm font-bold text-brand-blue">← {{ $location->customer->display_name }}</a>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950">{{ $location->name }}</h1>
                @if($location->is_primary)<span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-brand-blue-dark">Primary</span>@endif
                <span class="{{ $location->active ? 'status-active' : 'status-inactive' }}">{{ $location->active ? 'Active' : 'Inactive' }}</span>
            </div>
            <p class="mt-2 text-slate-600">{{ $location->formattedAddress() }}</p>
        </div>
        @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.locations.edit', $location) }}" class="button-secondary">Edit location</a>@endif
    </div>
    <div class="mt-6 grid gap-6 md:grid-cols-2">
        <section class="surface p-5">
            <h2 class="text-lg font-bold text-slate-950">Field information</h2>
            <dl class="mt-4 space-y-4 text-sm">
                <div><dt class="font-semibold text-slate-500">Primary contact</dt><dd class="mt-1 text-slate-900">{{ $location->primaryContact?->name ?: 'Not assigned' }}</dd></div>
                <div><dt class="font-semibold text-slate-500">Timezone</dt><dd class="mt-1 text-slate-900">{{ $location->timezone }}</dd></div>
                <div><dt class="font-semibold text-slate-500">Access instructions</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $location->access_instructions ?: 'No access instructions' }}</dd></div>
            </dl>
        </section>
        <section class="surface p-5">
            <h2 class="text-lg font-bold text-slate-950">Office-only notes</h2>
            <p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $location->site_notes ?: 'No site notes' }}</p>
        </section>
    </div>
</x-layouts.office>
