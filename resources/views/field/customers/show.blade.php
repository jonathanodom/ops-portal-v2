<x-layouts.field :title="$customer->display_name">
    <a href="{{ route('field.customers.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Customers</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $customer->display_name }}</h1>
    @php($contact = $customer->preferredContact)
    <section class="surface mt-5 p-5">
        <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Preferred contact</h2>
        @if($contact)
            <p class="mt-3 font-bold text-slate-950">{{ $contact->name }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $contact->role }}</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @if($contact->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact->phone) }}" class="button-primary">Call {{ $contact->phone }}</a>@endif
                @if($contact->email)<a href="mailto:{{ $contact->email }}" class="button-secondary">Email contact</a>@endif
            </div>
        @else <p class="mt-3 text-sm text-slate-500">No preferred contact is available.</p> @endif
    </section>
    <section class="mt-6">
        <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Active locations</h2>
        <div class="mt-3 space-y-3">
            @forelse($customer->serviceLocations as $location)
                <a href="{{ route('field.locations.show', $location) }}" class="surface block min-h-20 p-4">
                    <div class="flex items-center gap-2"><p class="font-bold text-slate-950">{{ $location->name }}</p>@if($location->is_primary)<span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-brand-blue-dark">Primary</span>@endif</div>
                    <p class="mt-2 text-sm text-slate-600">{{ $location->formattedAddress() }}</p>
                </a>
            @empty <div class="surface p-5 text-sm text-slate-500">No active locations.</div> @endforelse
        </div>
    </section>
</x-layouts.field>
