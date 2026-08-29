<x-layouts.office title="Add service location" width="form">
    <x-office.record-header title="Add service location" :back-href="route('office.customers.show', $customer)" :back-label="$customer->display_name" description="Add an operational address without leaving the customer record." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.locations.store', $customer) }}" class="office-form-shell">
        @csrf <div class="p-4">@include('office.locations._fields', ['location' => null])</div>
        <x-office.form-actions><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a><button class="button-primary">Add location</button></x-office.form-actions>
    </form>
</x-layouts.office>
