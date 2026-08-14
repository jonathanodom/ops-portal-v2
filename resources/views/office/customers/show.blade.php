<x-layouts.office :title="$customer->display_name" width="detail">
    @if (session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif

    <x-office.record-header
        :title="$customer->display_name"
        :back-href="route('office.customers.index')"
        back-label="Customers"
        :description="config('customers.types.'.$customer->type).($customer->legal_name ? ' · '.$customer->legal_name : '')"
    >
        <x-slot:badges><span class="{{ $customer->status === 'active' ? 'status-active' : ($customer->status === 'on_hold' ? 'status-hold' : 'status-inactive') }}">{{ config('customers.statuses.'.$customer->status) }}</span></x-slot:badges>
        @if ($activeMembership->hasCapability('customers.manage'))
            <x-slot:actions>
                <a href="{{ route('office.customers.edit', $customer) }}" class="button-secondary">Edit customer</a>
                <a href="{{ route('office.customers.locations.create', $customer) }}" class="button-primary">Add location</a>
            </x-slot:actions>
        @endif
    </x-office.record-header>

    <x-office.detail-nav :items="['overview' => 'Overview', 'history' => 'Service & invoice history', 'locations' => 'Locations', 'contacts' => 'Contacts'] + ($activeMembership->hasCapability('subscriptions.view') ? ['customer-services' => 'Customer Services'] : [])" />

    <div class="office-detail-grid" data-office-detail-grid>
        <div class="office-detail-main xl:order-first" data-office-detail-main>
            <section id="history" class="office-detail-section" aria-labelledby="history-heading">
                <div class="office-detail-section-header">
                    <div>
                        <h2 id="history-heading" class="office-detail-section-title">Service ticket history</h2>
                        <p class="mt-1 text-sm text-slate-500">Operational history across this customer’s service locations.</p>
                    </div>
                </div>
                <div class="office-detail-list">
                    @forelse($customer->serviceTickets as $ticket)
                        <a href="{{ route('office.service-tickets.show', $ticket) }}" class="office-detail-row grid gap-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><strong class="text-slate-950">{{ $ticket->ticket_number }}</strong><span class="{{ $ticket->status === 'completed' ? 'status-active' : ($ticket->status === 'canceled' ? 'status-inactive' : 'status-hold') }}">{{ Str::headline($ticket->status) }}</span></div>
                                <p class="mt-1 font-semibold text-slate-800">{{ $ticket->title }}</p>
                                <p class="mt-1 text-sm text-slate-500">{{ $ticket->serviceLocation?->name ?: 'Location unavailable' }} · {{ $purposes[$ticket->purpose] ?? Str::headline($ticket->purpose ?: 'service_call') }}</p>
                            </div>
                            <div class="text-sm"><p class="font-semibold text-slate-500">Visits</p><p class="mt-1 text-slate-800">{{ $ticket->visits_count }}</p><p class="mt-1 text-xs text-slate-500">{{ $billingDispositions[$ticket->billing_disposition] ?? Str::headline($ticket->billing_disposition ?: 'billable') }}</p></div>
                            <span class="text-sm font-bold text-brand-blue">Open<span class="sr-only"> {{ $ticket->ticket_number }}</span> →</span>
                        </a>
                    @empty
                        <p class="office-detail-empty">No service tickets have been recorded for this customer.</p>
                    @endforelse
                </div>
            </section>

            @if($activeMembership->hasCapability('invoices.view'))
                <section id="invoice-history" class="office-detail-section" aria-labelledby="invoice-history-heading">
                    <div class="office-detail-section-header">
                        <div>
                            <h2 id="invoice-history-heading" class="office-detail-section-title">Invoice history</h2>
                            <p class="mt-1 text-sm text-slate-500">Draft, issued, void, and payment status for this customer.</p>
                        </div>
                    </div>
                    <div class="office-detail-list">
                        @forelse($invoices as $invoice)
                            <a href="{{ route('office.invoices.show', $invoice) }}" class="office-detail-row grid gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto] sm:items-center">
                                <div><strong class="text-slate-950">{{ $invoice->invoice_number }}</strong><p class="mt-1 text-sm text-slate-500">{{ $invoice->serviceTicket?->ticket_number ?: 'Service ticket unavailable' }} · {{ $invoice->serviceLocation?->name ?: 'Location unavailable' }}</p></div>
                                <div class="text-sm"><p><span class="{{ $invoice->status === 'issued' ? 'status-active' : ($invoice->status === 'void' ? 'status-inactive' : 'status-hold') }}">{{ Str::headline($invoice->status) }}</span></p><p class="mt-1 text-slate-700">{{ Str::headline($invoice->paymentState()) }} · ${{ number_format($invoice->total_cents / 100, 2) }}</p></div>
                                <span class="text-sm font-bold text-brand-blue">Open<span class="sr-only"> {{ $invoice->invoice_number }}</span> →</span>
                            </a>
                        @empty
                            <p class="office-detail-empty">No invoices have been recorded for this customer.</p>
                        @endforelse
                    </div>
                </section>
            @endif

            <section id="locations" class="office-detail-section" aria-labelledby="locations-heading">
                <div class="office-detail-section-header">
                    <div>
                        <h2 id="locations-heading" class="office-detail-section-title">Service locations</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $customer->serviceLocations->count() }} {{ Str::plural('location', $customer->serviceLocations->count()) }}</p>
                    </div>
                </div>
                <div class="office-detail-list">
                    @forelse($customer->serviceLocations as $location)
                        <a href="{{ route('office.locations.show', $location) }}" class="office-detail-row grid gap-3 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] sm:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><strong class="text-slate-950">{{ $location->name }}</strong>@if($location->is_primary)<span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-brand-blue-dark">Primary</span>@endif</div>
                                <p class="mt-1 text-sm text-slate-500">{{ $location->formattedAddress() }}</p>
                            </div>
                            <div class="text-sm"><p class="font-semibold text-slate-500">Primary contact</p><p class="mt-1 text-slate-800">{{ $location->primaryContact?->name ?: 'Not assigned' }}</p></div>
                            <span class="{{ $location->active ? 'status-active' : 'status-inactive' }} w-fit">{{ $location->active ? 'Active' : 'Inactive' }}</span>
                        </a>
                    @empty
                        <p class="office-detail-empty">No service locations have been added.</p>
                    @endforelse
                </div>
            </section>

            <section id="contacts" class="office-detail-section" aria-labelledby="contacts-heading">
                <div class="office-detail-section-header">
                    <div>
                        <h2 id="contacts-heading" class="office-detail-section-title">Contacts</h2>
                        <p class="mt-1 text-sm text-slate-500">{{ $customer->contacts->count() }} {{ Str::plural('contact', $customer->contacts->count()) }}</p>
                    </div>
                    @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.contacts.create', $customer) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">Add contact</a>@endif
                </div>
                <div class="office-detail-list">
                    @forelse($customer->contacts as $contact)
                        <div class="office-detail-row grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2"><p class="font-bold text-slate-950">{{ $contact->name }}</p>@if($contact->is_preferred)<span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-bold text-brand-blue-dark">Preferred</span>@endif @unless($contact->active)<span class="status-inactive">Inactive</span>@endunless</div>
                                <p class="mt-1 text-sm text-slate-500">{{ $contact->role ?: 'Contact' }}</p>
                            </div>
                            <div class="text-sm text-slate-700">
                                <p>{{ $contact->phone ?: 'No phone' }}</p>
                                <p class="mt-1 break-all">{{ $contact->email ?: 'No email' }}</p>
                            </div>
                            @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.customers.contacts.edit', [$customer, $contact]) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">Edit<span class="sr-only"> {{ $contact->name }}</span></a>@endif
                        </div>
                    @empty
                        <p class="office-detail-empty">No contacts have been added.</p>
                    @endforelse
                </div>
            </section>

            @if($activeMembership->hasCapability('subscriptions.view'))
                <section id="customer-services" class="office-detail-section" aria-labelledby="customer-services-heading">
                    <div class="office-detail-section-header">
                        <div>
                            <h2 id="customer-services-heading" class="office-detail-section-title">Recurring customer Services</h2>
                            <p class="mt-1 text-sm text-slate-500">Tracked enrollment only. No automatic invoice or payment is created.</p>
                        </div>
                        @if($activeMembership->hasCapability('subscriptions.manage') && $customer->status === 'active')<a href="{{ route('office.customers.subscriptions.create', $customer) }}" class="button-primary">Add recurring Service</a>@endif
                    </div>
                    <div class="office-detail-list">
                        @forelse($customer->serviceEnrollments as $enrollment)
                            <a href="{{ route('office.subscriptions.show', $enrollment) }}" class="office-detail-row grid gap-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto] sm:items-center">
                                <div><strong class="text-slate-950">{{ $enrollment->service_name_snapshot }}</strong><p class="mt-1 text-sm text-slate-500">{{ $enrollment->service_code_snapshot }}@if($enrollment->variant_label_snapshot) Â· {{ $enrollment->variant_label_snapshot }}@endif</p></div>
                                <div class="text-sm"><p class="font-semibold text-slate-500">Scope</p><p class="mt-1 text-slate-800">{{ $enrollment->serviceLocation?->name ?: 'Customer-wide' }}</p></div>
                                <span class="{{ $enrollment->status === 'active' ? 'status-active' : ($enrollment->status === 'paused' ? 'status-hold' : 'status-inactive') }} w-fit">{{ ucfirst($enrollment->status) }}</span>
                            </a>
                        @empty
                            <p class="office-detail-empty">No recurring Services are enrolled for this Customer.</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>

        <aside id="overview" class="office-detail-rail order-first xl:order-last" aria-labelledby="overview-heading" data-office-detail-rail>
            <section class="office-detail-section p-5">
                <h2 id="overview-heading" class="office-detail-section-title">Customer overview</h2>
                <dl class="office-detail-definition mt-5">
                    <div><dt>Customer type</dt><dd>{{ config('customers.types.'.$customer->type) }}</dd></div>
                    <div><dt>Legal name</dt><dd>{{ $customer->legal_name ?: 'Not provided' }}</dd></div>
                    <div><dt>Phone</dt><dd>{{ $customer->phone ?: 'Not provided' }}</dd></div>
                    <div><dt>Email</dt><dd class="break-all">{{ $customer->email ?: 'Not provided' }}</dd></div>
                </dl>
            </section>
            <section class="office-detail-section p-5" aria-labelledby="customer-notes-heading">
                <h2 id="customer-notes-heading" class="office-detail-section-title">Office notes</h2>
                <p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $customer->notes ?: 'No notes' }}</p>
            </section>
        </aside>
    </div>
</x-layouts.office>
