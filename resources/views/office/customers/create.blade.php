<x-layouts.office title="Add customer" width="form">
    <x-office.record-header title="Add customer" :back-href="route('office.customers.index')" back-label="Customers" description="Create the customer and first service location together. The primary contact is optional." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.store') }}" class="office-form-shell">
        @csrf
        <x-office.form-section title="Customer" description="Identity, account status, and office contact details.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="type" class="form-label">Customer type</label><select id="type" name="type" class="form-input" required>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input" required>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="display_name" class="form-label">Display name</label><input id="display_name" name="display_name" class="form-input" value="{{ old('display_name') }}" required></div>
                <div><label for="legal_name" class="form-label">Legal name <span class="font-normal text-slate-500">(optional)</span></label><input id="legal_name" name="legal_name" class="form-input" value="{{ old('legal_name') }}"></div>
                <div><label for="phone" class="form-label">Main phone</label><input id="phone" name="phone" type="tel" class="form-input" value="{{ old('phone') }}"></div>
                <div><label for="email" class="form-label">Main email</label><input id="email" name="email" type="email" class="form-input" value="{{ old('email') }}"></div>
                <div class="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-4"><label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt'))><span class="font-bold">Customer is tax exempt</span></label><label for="tax_exemption_reference" class="form-label mt-2">Exemption reference</label><input id="tax_exemption_reference" name="tax_exemption_reference" class="form-input" value="{{ old('tax_exemption_reference') }}"><p class="mt-2 text-xs text-slate-500">New Quote revisions snapshot this status and reference.</p></div>
                <div class="sm:col-span-2"><label for="notes" class="form-label">Office notes</label><textarea id="notes" name="notes" class="form-textarea">{{ old('notes') }}</textarea><p class="mt-2 text-xs text-slate-500">Never shown in the field directory.</p></div>
            </div>
        </x-office.form-section>
        <x-office.form-section title="Preferred contact" description="Optional. An entered contact becomes the preferred customer contact.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="contact_name" class="form-label">Name</label><input id="contact_name" name="contact[name]" class="form-input" value="{{ old('contact.name') }}"></div>
                <div><label for="contact_role" class="form-label">Role</label><input id="contact_role" name="contact[role]" class="form-input" value="{{ old('contact.role') }}"></div>
                <div><label for="contact_phone" class="form-label">Phone</label><input id="contact_phone" name="contact[phone]" type="tel" class="form-input" value="{{ old('contact.phone') }}"></div>
                <div><label for="contact_email" class="form-label">Email</label><input id="contact_email" name="contact[email]" type="email" class="form-input" value="{{ old('contact.email') }}"></div>
            </div>
        </x-office.form-section>
        <x-office.form-section title="First service location" description="Required operational address and timezone.">
            @include('office.locations._fields', ['location' => null, 'contacts' => collect(), 'nested' => true])
        </x-office.form-section>
        <x-office.form-actions message="Customer, contact, and location save together."><a href="{{ route('office.customers.index') }}" class="button-secondary">Cancel</a><button class="button-primary">Create customer</button></x-office.form-actions>
    </form>
</x-layouts.office>
