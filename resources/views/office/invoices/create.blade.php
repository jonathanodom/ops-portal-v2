<x-layouts.office title="New Invoice" width="form">
    <x-office.record-header title="New invoice" :back-href="route('office.invoices.index')" back-label="Invoices" description="Create a direct invoice for equipment, one-off services, deposits, or billing that does not need a Service Ticket." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.invoices.store') }}" class="office-form-shell">
        @csrf
        <input type="hidden" name="creation_token" value="{{ old('creation_token', Str::uuid()) }}">
        <div class="p-4">@include('office.invoices._customer-picker')</div>
        <div class="border-t border-blue-200 bg-blue-50 p-3 text-sm text-blue-950"><p class="font-bold">The draft starts with no line items.</p><p class="mt-1">After creation, add Catalog or manual items, edit billing details, and follow the normal review and issue lifecycle.</p></div>
        <x-office.form-actions message="Financial content remains editable until the invoice is issued."><a href="{{ route('office.invoices.index') }}" class="button-secondary">Cancel</a><button class="button-primary">Create draft</button></x-office.form-actions>
    </form>
</x-layouts.office>
