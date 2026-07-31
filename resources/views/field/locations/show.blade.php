<x-layouts.field :title="$location->name">
    <a href="{{ route('field.customers.show', $location->customer) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← {{ $location->customer->display_name }}</a>
    <h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $location->name }}</h1>
    <p class="mt-2 text-sm text-slate-600">{{ $location->formattedAddress() }}</p>
    <section class="surface mt-5 p-5">
        <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Access instructions</h2>
        <p class="mt-3 whitespace-pre-line text-base leading-7 text-slate-900">{{ $location->access_instructions ?: 'No access instructions provided.' }}</p>
    </section>
    @if($location->primaryContact)
        <section class="surface mt-4 p-5">
            <h2 class="text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Location contact</h2>
            <p class="mt-3 font-bold text-slate-950">{{ $location->primaryContact->name }}</p>
            <div class="mt-4 grid gap-3">
                @if($location->primaryContact->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $location->primaryContact->phone) }}" class="button-primary">Call {{ $location->primaryContact->phone }}</a>@endif
                @if($location->primaryContact->email)<a href="mailto:{{ $location->primaryContact->email }}" class="button-secondary">Email contact</a>@endif
            </div>
        </section>
    @endif
    <div class="surface mt-4 p-5"><p class="text-sm font-semibold text-slate-500">Timezone</p><p class="mt-1 font-bold text-slate-900">{{ $location->timezone }}</p></div>
</x-layouts.field>
