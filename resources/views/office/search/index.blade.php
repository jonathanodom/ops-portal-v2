<x-layouts.office title="Customer Directory Search" width="workspace">
    <x-office.page-header title="Customer Directory Search" description="Find canonical Customers, Contacts, and Service Locations." eyebrow="NewDay Home">
        <x-slot:actions><a class="button-secondary w-full sm:w-auto" href="{{ route('office.home') }}">Back to Home</a></x-slot:actions>
    </x-office.page-header>

    <form method="GET" action="{{ route('office.search') }}" class="surface mt-6 p-4 sm:p-5" role="search">
        <label class="form-label" for="directory-search">Customer, contact, or location</label>
        <div class="mt-2 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
            <input class="form-input" id="directory-search" name="q" value="{{ $query }}" minlength="2" placeholder="Name, phone, email, or address" autocomplete="off">
            <button class="button-primary w-full sm:w-auto">Search directory</button>
        </div>
        <p class="mt-2 text-sm text-slate-600">Enter at least two characters. Search is limited to your active Organization.</p>
    </form>

    @if(!$searched)
        <div class="surface mt-6 p-8 text-center"><p class="font-bold text-slate-950">Enter at least two characters</p><p class="mt-1 text-sm text-slate-600">No directory queries run until the minimum is met.</p></div>
    @elseif($customers->isEmpty() && $contacts->isEmpty() && $locations->isEmpty())
        <div class="surface mt-6 p-8 text-center"><p class="font-bold text-slate-950">No directory records found</p><p class="mt-1 text-sm text-slate-600">Try a different name, phone number, email, or address.</p></div>
    @else
        <div class="mt-6 grid gap-6 xl:grid-cols-3" aria-live="polite">
            <section class="surface overflow-hidden" aria-labelledby="customer-results-heading">
                <div class="border-b border-slate-200 p-5"><h2 id="customer-results-heading" class="text-lg font-bold">Customers</h2><p class="mt-1 text-sm text-slate-600">{{ $customers->count() }} result(s)</p></div>
                <div class="divide-y divide-slate-200">@forelse($customers as $customer)<a class="block min-h-16 p-4 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-blue" href="{{ route('office.customers.show', $customer) }}"><span class="font-bold text-brand-blue">{{ $customer->display_name }}</span><span class="mt-1 block text-sm text-slate-600">{{ $customer->legal_name ?: ($customer->email ?: $customer->phone) }}</span>@if($customer->status !== 'active')<span class="status-muted mt-2">{{ Str::headline($customer->status) }}</span>@endif</a>@empty<p class="p-5 text-sm text-slate-600">No matching Customers.</p>@endforelse</div>
            </section>
            <section class="surface overflow-hidden" aria-labelledby="contact-results-heading">
                <div class="border-b border-slate-200 p-5"><h2 id="contact-results-heading" class="text-lg font-bold">Contacts</h2><p class="mt-1 text-sm text-slate-600">{{ $contacts->count() }} result(s)</p></div>
                <div class="divide-y divide-slate-200">@forelse($contacts as $contact)<a class="block min-h-16 p-4 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-blue" href="{{ route('office.customers.show', $contact->customer) }}#contacts"><span class="font-bold text-brand-blue">{{ $contact->name }}</span><span class="mt-1 block text-sm text-slate-600">{{ $contact->customer->display_name }}{{ $contact->role ? ' · '.$contact->role : '' }}</span>@if(!$contact->active)<span class="status-muted mt-2">Inactive</span>@endif</a>@empty<p class="p-5 text-sm text-slate-600">No matching Contacts.</p>@endforelse</div>
            </section>
            <section class="surface overflow-hidden" aria-labelledby="location-results-heading">
                <div class="border-b border-slate-200 p-5"><h2 id="location-results-heading" class="text-lg font-bold">Service Locations</h2><p class="mt-1 text-sm text-slate-600">{{ $locations->count() }} result(s)</p></div>
                <div class="divide-y divide-slate-200">@forelse($locations as $location)<a class="block min-h-16 p-4 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-blue" href="{{ route('office.locations.show', $location) }}"><span class="font-bold text-brand-blue">{{ $location->name }}</span><span class="mt-1 block text-sm text-slate-600">{{ $location->customer->display_name }} · {{ $location->formattedAddress() }}</span>@if(!$location->active)<span class="status-muted mt-2">Inactive</span>@endif</a>@empty<p class="p-5 text-sm text-slate-600">No matching Service Locations.</p>@endforelse</div>
            </section>
        </div>
    @endif
</x-layouts.office>
