<x-layouts.office title="New Invoice" width="form">
    <a href="{{ route('office.invoices.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">&larr; Invoices</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">New invoice</h1>
    <p class="mt-2 max-w-2xl text-slate-600">Create a direct invoice for equipment, one-off services, deposits, or other billing that does not need a Service Ticket.</p>

    <form method="POST" action="{{ route('office.invoices.store') }}" class="surface mt-6 p-5 sm:p-6">
        @csrf
        <input type="hidden" name="creation_token" value="{{ old('creation_token', Str::uuid()) }}">
        <x-form-errors />
        @include('office.invoices._customer-picker')
        <div class="mt-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
            <p class="font-bold">The draft starts with no line items.</p>
            <p class="mt-1">After creation, add Catalog or manual items, edit billing details, and follow the normal review and issue lifecycle.</p>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            <button class="button-primary">Create draft</button>
            <a href="{{ route('office.invoices.index') }}" class="button-secondary">Cancel</a>
        </div>
    </form>
</x-layouts.office>
