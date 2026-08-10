<x-layouts.office :title="$location->name" width="detail">
    @if (session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif

    <x-office.record-header
        :title="$location->name"
        :back-href="route('office.customers.show', $location->customer)"
        :back-label="$location->customer->display_name"
        :description="$location->formattedAddress()"
    >
        <x-slot:badges>
            @if($location->is_primary)<span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-brand-blue-dark">Primary</span>@endif
            <span class="{{ $location->active ? 'status-active' : 'status-inactive' }}">{{ $location->active ? 'Active' : 'Inactive' }}</span>
        </x-slot:badges>
    </x-office.record-header>

    <div class="office-detail-grid" data-office-detail-grid>
        <div class="office-detail-main xl:order-first" data-office-detail-main>
            <section class="office-detail-section" aria-labelledby="field-information-heading">
                <div class="office-detail-section-header"><h2 id="field-information-heading" class="office-detail-section-title">Field information</h2></div>
                <dl class="office-detail-definition p-5">
                    <div><dt>Service address</dt><dd>{{ $location->formattedAddress() }}</dd></div>
                    <div>
                        <dt>Primary contact</dt>
                        <dd>
                            <p>{{ $location->primaryContact?->name ?: 'Not assigned' }}</p>
                            @if($location->primaryContact?->role)<p class="mt-1 text-slate-600">{{ $location->primaryContact->role }}</p>@endif
                            @if($location->primaryContact?->phone)<p class="mt-1">{{ $location->primaryContact->phone }}</p>@endif
                            @if($location->primaryContact?->email)<p class="mt-1 break-all">{{ $location->primaryContact->email }}</p>@endif
                        </dd>
                    </div>
                    <div><dt>Access instructions</dt><dd class="whitespace-pre-line">{{ $location->access_instructions ?: 'No access instructions' }}</dd></div>
                </dl>
            </section>

            <section class="office-detail-section" aria-labelledby="site-notes-heading">
                <div class="office-detail-section-header">
                    <div><p class="text-xs font-bold uppercase tracking-[0.1em] text-slate-500">Office only</p><h2 id="site-notes-heading" class="office-detail-section-title mt-1">Site notes</h2></div>
                </div>
                <p class="whitespace-pre-line p-5 text-sm text-slate-700">{{ $location->site_notes ?: 'No site notes' }}</p>
            </section>
        </div>

        <aside class="office-detail-rail order-first xl:order-last" aria-labelledby="location-context-heading" data-office-detail-rail>
            <section class="office-detail-section p-5">
                <h2 id="location-context-heading" class="office-detail-section-title">Location overview</h2>
                <dl class="office-detail-definition mt-5">
                    <div><dt>Customer</dt><dd><a href="{{ route('office.customers.show', $location->customer) }}" class="font-bold text-brand-blue">{{ $location->customer->display_name }}</a></dd></div>
                    <div><dt>Timezone</dt><dd>{{ $location->timezone }}</dd></div>
                    <div><dt>Location role</dt><dd>{{ $location->is_primary ? 'Primary service location' : 'Additional service location' }}</dd></div>
                    <div><dt>Status</dt><dd>{{ $location->active ? 'Active' : 'Inactive' }}</dd></div>
                </dl>
                @if ($activeMembership->hasCapability('customers.manage'))<a href="{{ route('office.locations.edit', $location) }}" class="button-secondary mt-6 w-full">Edit location</a>@endif
            </section>
        </aside>
    </div>
</x-layouts.office>
