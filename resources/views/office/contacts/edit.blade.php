<x-layouts.office title="Edit contact">
    <a href="{{ route('office.customers.show', $customer) }}" class="text-sm font-bold text-brand-blue">← {{ $customer->display_name }}</a>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Edit contact</h1>
    <p class="mt-2 text-slate-600">Deactivating a contact removes it from field views and clears it from assigned locations.</p>
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.contacts.update', [$customer, $contact]) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf @method('PUT') @include('office.contacts._fields')
        <div class="mt-6 flex gap-3"><button class="button-primary">Save contact</button><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
