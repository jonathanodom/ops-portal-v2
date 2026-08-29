<x-layouts.office title="Add contact" width="form">
    <x-office.record-header title="Add contact" :back-href="route('office.customers.show', $customer)" :back-label="$customer->display_name" description="Add an operational contact to this customer." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.contacts.store', $customer) }}" class="office-form-shell">
        @csrf <div class="p-4">@include('office.contacts._fields', ['contact' => null])</div>
        <x-office.form-actions><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a><button class="button-primary">Add contact</button></x-office.form-actions>
    </form>
</x-layouts.office>
