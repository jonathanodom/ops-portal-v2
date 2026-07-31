<x-layouts.office title="Add customer">
    <div class="mb-6">
        <a href="{{ route('office.customers.index') }}" class="text-sm font-bold text-brand-blue">← Customers</a>
        <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Add customer</h1>
        <p class="mt-2 text-slate-600">Create the customer and first service location together. The primary contact is optional.</p>
    </div>
    <x-form-errors />
    <form method="POST" action="{{ route('office.customers.store') }}" class="space-y-6">
        @csrf
        <section class="surface p-5 sm:p-6">
            <h2 class="text-lg font-bold text-slate-950">Customer</h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><label for="type" class="form-label">Customer type</label><select id="type" name="type" class="form-input" required>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input" required>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="display_name" class="form-label">Display name</label><input id="display_name" name="display_name" class="form-input" value="{{ old('display_name') }}" required></div>
                <div><label for="legal_name" class="form-label">Legal name <span class="font-normal text-slate-500">(optional)</span></label><input id="legal_name" name="legal_name" class="form-input" value="{{ old('legal_name') }}"></div>
                <div><label for="phone" class="form-label">Main phone</label><input id="phone" name="phone" type="tel" class="form-input" value="{{ old('phone') }}"></div>
                <div><label for="email" class="form-label">Main email</label><input id="email" name="email" type="email" class="form-input" value="{{ old('email') }}"></div>
                <div class="sm:col-span-2"><label for="notes" class="form-label">Office notes</label><textarea id="notes" name="notes" class="form-textarea">{{ old('notes') }}</textarea><p class="mt-2 text-xs text-slate-500">Never shown in the field directory.</p></div>
            </div>
        </section>
        <section class="surface p-5 sm:p-6">
            <h2 class="text-lg font-bold text-slate-950">Preferred contact <span class="text-sm font-normal text-slate-500">(optional)</span></h2>
            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <div><label for="contact_name" class="form-label">Name</label><input id="contact_name" name="contact[name]" class="form-input" value="{{ old('contact.name') }}"></div>
                <div><label for="contact_role" class="form-label">Role</label><input id="contact_role" name="contact[role]" class="form-input" value="{{ old('contact.role') }}"></div>
                <div><label for="contact_phone" class="form-label">Phone</label><input id="contact_phone" name="contact[phone]" type="tel" class="form-input" value="{{ old('contact.phone') }}"></div>
                <div><label for="contact_email" class="form-label">Email</label><input id="contact_email" name="contact[email]" type="email" class="form-input" value="{{ old('contact.email') }}"></div>
            </div>
        </section>
        <section class="surface p-5 sm:p-6">
            <h2 class="text-lg font-bold text-slate-950">First service location</h2>
            @include('office.locations._fields', ['location' => null, 'contacts' => collect(), 'nested' => true])
        </section>
        <div class="flex flex-wrap gap-3"><button class="button-primary">Create customer</button><a href="{{ route('office.customers.index') }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
