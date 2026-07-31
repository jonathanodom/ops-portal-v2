<x-layouts.office title="Add service location">
    <a href="{{ route('office.customers.show', $customer) }}" class="text-sm font-bold text-brand-blue">← {{ $customer->display_name }}</a>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Add service location</h1>
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.locations.store', $customer) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf @include('office.locations._fields', ['location' => null])
        <div class="mt-6 flex gap-3"><button class="button-primary">Add location</button><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
