<dialog
    id="quick-customer-dialog"
    data-quick-customer-dialog
    aria-labelledby="quick-customer-title"
    class="office-standard-dialog"
>
    <form method="POST" action="{{ route('office.service-tickets.quick-customers.store') }}" data-quick-customer-form class="flex h-full min-h-0 flex-col">
        @csrf
        <header class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.12em] text-brand-orange">Quick add</p>
                <h2 id="quick-customer-title" class="text-xl font-bold text-slate-950">Add customer and location</h2>
            </div>
            <button type="button" class="button-secondary min-w-11 px-3" data-quick-customer-close aria-label="Close quick customer form">Close</button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
            <div class="mb-5 hidden rounded-lg border p-4 font-semibold" tabindex="-1" data-quick-customer-status aria-live="assertive"></div>

            <section>
                <h3 class="text-lg font-bold text-slate-950">Customer</h3>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="quick_type">Customer type</label>
                        <select class="form-input" id="quick_type" name="type" required>
                            @foreach($customerTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="type"></p>
                    </div>
                    <div>
                        <label class="form-label" for="quick_display_name">Display name</label>
                        <input class="form-input" id="quick_display_name" name="display_name" required maxlength="255">
                        <p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="display_name"></p>
                    </div>
                    <div>
                        <label class="form-label" for="quick_phone">Main phone <span class="font-normal text-slate-500">(optional)</span></label>
                        <input class="form-input" id="quick_phone" name="phone" type="tel" maxlength="40">
                        <p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="phone"></p>
                    </div>
                    <div>
                        <label class="form-label" for="quick_email">Main email <span class="font-normal text-slate-500">(optional)</span></label>
                        <input class="form-input" id="quick_email" name="email" type="email" maxlength="255">
                        <p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="email"></p>
                    </div>
                </div>
            </section>

            <section class="mt-6 border-t border-slate-200 pt-6">
                <h3 class="text-lg font-bold text-slate-950">Preferred contact <span class="text-sm font-normal text-slate-500">(optional)</span></h3>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div><label class="form-label" for="quick_contact_name">Name</label><input class="form-input" id="quick_contact_name" name="contact[name]" maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="contact.name"></p></div>
                    <div><label class="form-label" for="quick_contact_role">Role</label><input class="form-input" id="quick_contact_role" name="contact[role]" maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="contact.role"></p></div>
                    <div><label class="form-label" for="quick_contact_phone">Phone</label><input class="form-input" id="quick_contact_phone" name="contact[phone]" type="tel" maxlength="40"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="contact.phone"></p></div>
                    <div><label class="form-label" for="quick_contact_email">Email</label><input class="form-input" id="quick_contact_email" name="contact[email]" type="email" maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="contact.email"></p></div>
                </div>
            </section>

            <section class="mt-6 border-t border-slate-200 pt-6">
                <h3 class="text-lg font-bold text-slate-950">First service location</h3>
                <div class="mt-4 grid gap-5 sm:grid-cols-2">
                    <div><label class="form-label" for="quick_location_name">Location name</label><input class="form-input" id="quick_location_name" name="location[name]" value="Primary service location" required maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.name"></p></div>
                    <div><label class="form-label" for="quick_address_line_1">Address line 1</label><input class="form-input" id="quick_address_line_1" name="location[address_line_1]" required maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.address_line_1"></p></div>
                    <div><label class="form-label" for="quick_address_line_2">Address line 2 <span class="font-normal text-slate-500">(optional)</span></label><input class="form-input" id="quick_address_line_2" name="location[address_line_2]" maxlength="255"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.address_line_2"></p></div>
                    <div><label class="form-label" for="quick_city">City</label><input class="form-input" id="quick_city" name="location[city]" required maxlength="100"><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.city"></p></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="form-label" for="quick_state">State</label><select class="form-input" id="quick_state" name="location[state]" required><option value="">Select</option>@foreach($states as $state)<option value="{{ $state }}" @selected($state === 'TX')>{{ $state }}</option>@endforeach</select><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.state"></p></div>
                        <div><label class="form-label" for="quick_postal_code">ZIP code</label><input class="form-input" id="quick_postal_code" name="location[postal_code]" inputmode="numeric" required><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.postal_code"></p></div>
                    </div>
                    <div><label class="form-label" for="quick_timezone">Timezone</label><input class="form-input" id="quick_timezone" name="location[timezone]" value="{{ $defaultTimezone }}" required><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.timezone"></p></div>
                    <div class="sm:col-span-2"><label class="form-label" for="quick_access_instructions">Field access instructions <span class="font-normal text-slate-500">(optional)</span></label><textarea class="form-textarea" id="quick_access_instructions" name="location[access_instructions]" maxlength="5000"></textarea><p class="mt-1 hidden text-sm font-semibold text-red-700" data-quick-error-for="location.access_instructions"></p><p class="mt-2 text-xs text-slate-500">Visible to authorized field users.</p></div>
                </div>
            </section>
        </div>

        <footer class="sticky bottom-0 flex flex-wrap gap-3 border-t border-slate-200 bg-white px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))] sm:px-6">
            <button class="button-primary" type="submit" data-quick-customer-submit>Save and select customer</button>
            <button class="button-secondary" type="button" data-quick-customer-close>Cancel</button>
        </footer>
    </form>
</dialog>
