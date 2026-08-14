@php
    $selectedLocationId = (string) old('service_location_id', '');
    $selectedContactId = (string) old('contact_id', '');
@endphp
<section
    data-customer-picker
    data-search-url="{{ route('office.invoices.customer-options') }}"
    class="rounded-lg border border-slate-200 bg-slate-50 p-4 sm:p-5"
>
    <div class="grid gap-5 md:grid-cols-2">
        <div class="relative md:col-span-2">
            <label class="form-label" for="customer_search">Customer</label>
            <input
                class="form-input"
                id="customer_search"
                type="search"
                value="{{ $selectedCustomer?->display_name }}"
                autocomplete="off"
                role="combobox"
                aria-autocomplete="list"
                aria-controls="customer_search_results"
                aria-expanded="false"
                aria-describedby="customer_search_help customer_search_status"
                placeholder="Search name, phone, email, location, or address"
            >
            <input id="customer_id" name="customer_id" type="hidden" value="{{ old('customer_id') }}" required>
            <p id="customer_search_help" class="mt-2 text-sm text-slate-600">Enter at least two characters, then choose an active customer.</p>
            <p id="customer_search_status" class="mt-2 text-sm font-semibold text-slate-700" aria-live="polite" data-customer-search-status></p>
            <button type="button" class="mt-2 hidden min-h-11 text-sm font-bold text-brand-blue underline" data-customer-search-retry>Retry search</button>
            <div id="customer_search_results" class="absolute z-20 mt-2 hidden max-h-80 w-full overflow-y-auto rounded-lg border border-slate-300 bg-white p-1 shadow-lg" role="listbox" data-customer-search-results></div>
            @error('customer_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        </div>

        <div>
            <label class="form-label" for="service_location_id">Service location</label>
            <select class="form-input" id="service_location_id" name="service_location_id" required @disabled(!$selectedCustomer)>
                <option value="">Select location</option>
                @foreach($selectedCustomer?->serviceLocations ?? [] as $location)
                    <option value="{{ $location->id }}" data-primary-contact="{{ $location->primary_contact_id }}" @selected($selectedLocationId === (string) $location->id)>{{ $location->name }} &mdash; {{ $location->address_line_1 }}, {{ $location->city }}</option>
                @endforeach
            </select>
            @error('service_location_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="form-label" for="contact_id">Billing contact <span class="font-normal text-slate-500">(optional)</span></label>
            <select class="form-input" id="contact_id" name="contact_id" @disabled(!$selectedCustomer)>
                <option value="">Use location/customer default</option>
                @foreach($selectedCustomer?->contacts ?? [] as $contact)
                    <option value="{{ $contact->id }}" data-preferred="{{ $contact->is_preferred ? '1' : '0' }}" @selected($selectedContactId === (string) $contact->id)>{{ $contact->name }}</option>
                @endforeach
            </select>
            @error('contact_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>
</section>
