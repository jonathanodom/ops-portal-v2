<x-layouts.office title="Edit service location">
    <a href="{{ route('office.locations.show', $location) }}" class="text-sm font-bold text-brand-blue">← {{ $location->name }}</a>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Edit service location</h1>
    <p class="mt-2 text-slate-600">Inactive locations remain available to office history but disappear from the field directory.</p>
    <x-form-errors />
    <form method="POST" action="{{ route('office.locations.update', $location) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf @method('PUT') @include('office.locations._fields')
        <div class="mt-6 flex gap-3"><button class="button-primary">Save location</button><a href="{{ route('office.locations.show', $location) }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
