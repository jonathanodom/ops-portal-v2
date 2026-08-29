<x-layouts.office title="Edit customer">
    <a href="{{ route('office.customers.show', $customer) }}" class="text-sm font-bold text-brand-blue">← {{ $customer->display_name }}</a>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Edit customer</h1>
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.update', $customer) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf @method('PUT')
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label for="type" class="form-label">Customer type</label><select id="type" name="type" class="form-input" required>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(old('type', $customer->type) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input" required>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(old('status', $customer->status) === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="display_name" class="form-label">Display name</label><input id="display_name" name="display_name" class="form-input" value="{{ old('display_name', $customer->display_name) }}" required></div>
            <div><label for="legal_name" class="form-label">Legal name</label><input id="legal_name" name="legal_name" class="form-input" value="{{ old('legal_name', $customer->legal_name) }}"></div>
            <div><label for="phone" class="form-label">Main phone</label><input id="phone" name="phone" type="tel" class="form-input" value="{{ old('phone', $customer->phone) }}"></div>
            <div><label for="email" class="form-label">Main email</label><input id="email" name="email" type="email" class="form-input" value="{{ old('email', $customer->email) }}"></div>
            <div class="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-4"><label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt', $customer->tax_exempt))><span class="font-bold">Customer is tax exempt</span></label><label for="tax_exemption_reference" class="form-label mt-2">Exemption reference</label><input id="tax_exemption_reference" name="tax_exemption_reference" class="form-input" value="{{ old('tax_exemption_reference', $customer->tax_exemption_reference) }}"><p class="mt-2 text-xs text-slate-500">Existing Quote revisions retain their original exemption snapshot.</p></div>
            <div class="sm:col-span-2"><label for="notes" class="form-label">Office notes</label><textarea id="notes" name="notes" class="form-textarea">{{ old('notes', $customer->notes) }}</textarea></div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3"><button class="button-primary">Save customer</button><a href="{{ route('office.customers.show', $customer) }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
