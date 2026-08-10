@php
    $selectedLocationId = (string) old('service_location_id', '');
    $selectedContactId = (string) old('contact_id', '');
@endphp
<section
    data-ticket-customer-picker
    data-search-url="{{ route('office.service-tickets.customer-options') }}"
    class="rounded-lg border border-slate-200 bg-slate-50 p-4 sm:p-5"
>
    <div class="grid gap-5 md:grid-cols-2">
        <div class="relative md:col-span-2">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="min-w-0 flex-1">
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
                </div>
            </div>
            <p id="customer_search_help" class="mt-2 text-sm text-slate-600">Enter at least two characters, then choose an active customer.</p>
            <p id="customer_search_status" class="mt-2 text-sm font-semibold text-slate-700" aria-live="polite" data-customer-search-status></p>
            <button type="button" class="mt-2 hidden min-h-11 text-sm font-bold text-brand-blue underline" data-customer-search-retry>Retry search</button>
            <div id="customer_search_results" class="absolute z-20 mt-2 hidden max-h-80 w-full overflow-y-auto rounded-lg border border-slate-300 bg-white p-1 shadow-lg" role="listbox" data-customer-search-results></div>
            @error('customer_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
            @if($canQuickAddCustomer)
                <div class="mt-3 hidden rounded-lg border border-brand-orange bg-orange-50 p-4" data-customer-empty>
                    <p class="font-semibold text-slate-950">No matching active customer was found.</p>
                    <button type="button" class="button-secondary mt-3 border-brand-orange text-slate-950" data-quick-customer-open>Add customer and location</button>
                </div>
            @endif
        </div>

        <div>
            <label class="form-label" for="service_location_id">Service location</label>
            <select class="form-input" id="service_location_id" name="service_location_id" required @disabled(!$selectedCustomer)>
                <option value="">Select location</option>
                @foreach($selectedCustomer?->serviceLocations ?? [] as $location)
                    <option value="{{ $location->id }}" data-primary-contact="{{ $location->primary_contact_id }}" @selected($selectedLocationId === (string) $location->id)>{{ $location->name }} — {{ $location->address_line_1 }}, {{ $location->city }}</option>
                @endforeach
            </select>
            @error('service_location_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="form-label" for="contact_id">Designated contact <span class="font-normal text-slate-500">(optional)</span></label>
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

<div class="mt-5 grid gap-5 md:grid-cols-2">
    <div>
        <label class="form-label" for="title">Ticket title</label>
        <input class="form-input" id="title" name="title" value="{{ old('title', $ticket->title ?? '') }}" required maxlength="255">
    </div>
    <div>
        <label class="form-label" for="priority">Priority</label>
        <select class="form-input" id="priority" name="priority" required>
            @foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(old('priority', $ticket->priority ?? 'normal') === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="source">Source</label>
        <select class="form-input" id="source" name="source" required>
            @foreach($sources as $value => $label)<option value="{{ $value }}" @selected(old('source', $ticket->source ?? 'internal') === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
</div>
