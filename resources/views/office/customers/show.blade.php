<x-layouts.office :title="$customer->display_name">
    @if (session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <a href="{{ route('office.customers.index') }}" class="text-sm font-bold text-brand-blue">← Customers</a>
            <div class="mt-3 flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-bold tracking-tight text-slate-950">{{ $customer->display_name }}</h1>
                <span class="{{ $customer->status === 'active' ? 'status-active' : ($customer->status === 'on_hold' ? 'status-hold' : 'status-inactive') }}">{{ config('customers.statuses.'.$customer->status) }}</span>
            </div>
            <p class="mt-2 text-sm text-slate-500">{{ config('customers.types.'.$customer->type) }}@if($customer->legal_name) · {{ $customer->legal_name }}@endif</p>
        </div>
        @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.edit', $customer) }}" class="button-secondary">Edit customer</a>@endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="surface p-5 lg:col-span-2">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-bold text-slate-950">Service locations</h2>
                @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.locations.create', $customer) }}" class="text-sm font-bold text-brand-blue">Add location</a>@endif
            </div>
            <div class="mt-4 divide-y divide-slate-200">
                @forelse($customer->serviceLocations as $location)
                    <a href="{{ route('office.locations.show', $location) }}" class="block min-h-16 py-4 hover:text-brand-blue">
                        <div class="flex flex-wrap items-center gap-2"><strong>{{ $location->name }}</strong>@if($location->is_primary)<span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-brand-blue-dark">Primary</span>@endif @unless($location->active)<span class="status-inactive">Inactive</span>@endunless</div>
                        <p class="mt-1 text-sm text-slate-500">{{ $location->formattedAddress() }}</p>
                    </a>
                @empty <p class="py-6 text-sm text-slate-500">No service locations.</p> @endforelse
            </div>
        </section>
        <aside class="surface p-5">
            <h2 class="text-lg font-bold text-slate-950">Customer details</h2>
            <dl class="mt-4 space-y-4 text-sm">
                <div><dt class="font-semibold text-slate-500">Phone</dt><dd class="mt-1 text-slate-900">{{ $customer->phone ?: 'Not provided' }}</dd></div>
                <div><dt class="font-semibold text-slate-500">Email</dt><dd class="mt-1 break-all text-slate-900">{{ $customer->email ?: 'Not provided' }}</dd></div>
                <div><dt class="font-semibold text-slate-500">Office notes</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $customer->notes ?: 'No notes' }}</dd></div>
            </dl>
        </aside>
    </div>

    <section class="surface mt-6 p-5">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-bold text-slate-950">Contacts</h2>
            @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.contacts.create', $customer) }}" class="text-sm font-bold text-brand-blue">Add contact</a>@endif
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($customer->contacts as $contact)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-center gap-2"><p class="font-bold text-slate-950">{{ $contact->name }}</p>@if($contact->is_preferred)<span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-brand-blue-dark">Preferred</span>@endif @unless($contact->active)<span class="status-inactive">Inactive</span>@endunless</div>
                    <p class="mt-1 text-sm text-slate-500">{{ $contact->role ?: 'Contact' }}</p>
                    <p class="mt-3 text-sm text-slate-700">{{ $contact->phone ?: 'No phone' }}<br>{{ $contact->email ?: 'No email' }}</p>
                    @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.contacts.edit', [$customer, $contact]) }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">Edit contact</a>@endif
                </div>
            @empty <p class="text-sm text-slate-500">No contacts.</p> @endforelse
        </div>
    </section>
</x-layouts.office>
