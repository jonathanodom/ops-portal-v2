<x-layouts.office title="Edit contact" width="form">
    <x-office.record-header title="Edit contact" :back-href="route('office.customers.show', $customer)" :back-label="$customer->display_name" description="Deactivating a contact removes it from field views and assigned locations." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.contacts.update', [$customer, $contact]) }}" class="office-form-shell">
        @csrf @method('PUT') <div class="p-4">@include('office.contacts._fields')</div>
        <x-office.form-actions><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a><button class="button-primary">Save contact</button></x-office.form-actions>
    </form>
</x-layouts.office>
