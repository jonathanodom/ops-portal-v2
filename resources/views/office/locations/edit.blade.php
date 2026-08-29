<x-layouts.office title="Edit service location" width="form">
    <x-office.record-header title="Edit service location" :back-href="route('office.locations.show', $location)" :back-label="$location->name" description="Inactive locations remain in office history but disappear from the field directory." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.locations.update', $location) }}" class="office-form-shell">
        @csrf @method('PUT') <div class="p-4">@include('office.locations._fields')</div>
        <x-office.form-actions><a href="{{ route('office.locations.show', $location) }}" class="button-secondary">Cancel</a><button class="button-primary">Save location</button></x-office.form-actions>
    </form>
</x-layouts.office>
