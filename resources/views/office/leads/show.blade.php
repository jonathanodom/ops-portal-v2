<x-layouts.office :title="$lead->first_name.' '.$lead->last_name" width="detail">
    @if(session('status'))<x-office.alert>{{ session('status') }}</x-office.alert>@endif
    @if($errors->any())<div class="mb-5 border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">Lead needs attention</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <x-office.record-header :title="$lead->first_name.' '.$lead->last_name" :back-href="route('office.leads.index')" back-label="Leads" :description="collect([$lead->company,$lead->service_interest])->filter()->implode(' · ')">
        <x-slot:badges><span class="{{ $lead->status === 'received' ? 'status-priority' : ($lead->status === 'converted' ? 'status-success' : 'status-muted') }}">{{ str($lead->status)->headline() }}</span><span class="status-muted">{{ $lead->customer_type }}</span></x-slot:badges>
        @if($lead->status==='converted' && $lead->opportunity)
            <x-slot:actions><a class="button-primary" href="{{ route('office.opportunities.show',$lead->opportunity) }}">Open Opportunity</a></x-slot:actions>
        @endif
    </x-office.record-header>

    <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
            <section class="surface p-5"><h2 class="text-xl font-bold">Inquiry</h2><dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase text-slate-500">Service interest</dt><dd class="mt-1 font-semibold">{{ $lead->service_interest }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">Selected plan</dt><dd class="mt-1">{{ $lead->selected_plan ?: 'Not selected' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">Timeline</dt><dd class="mt-1">{{ $lead->timeline ?: 'Not provided' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">Preferred contact</dt><dd class="mt-1">{{ $lead->preferred_contact }}</dd></div></dl><div class="mt-5 border-t border-slate-200 pt-4"><h3 class="font-bold">Details</h3><p class="mt-2 whitespace-pre-line text-slate-700">{{ $lead->details }}</p></div></section>

            <section class="surface p-5"><h2 class="text-xl font-bold">Attribution</h2><dl class="mt-4 grid gap-4 sm:grid-cols-2"><div><dt class="text-xs font-bold uppercase text-slate-500">Originating page</dt><dd class="mt-1 break-words">{{ $lead->originating_page ?: 'Not recorded' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">Referrer</dt><dd class="mt-1 break-words">{{ $lead->referrer ?: 'Not recorded' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">UTM source / medium</dt><dd class="mt-1">{{ collect([$lead->utm_source,$lead->utm_medium])->filter()->implode(' / ') ?: 'Not recorded' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">UTM campaign</dt><dd class="mt-1">{{ $lead->utm_campaign ?: 'Not recorded' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">UTM term</dt><dd class="mt-1">{{ $lead->utm_term ?: 'Not recorded' }}</dd></div><div><dt class="text-xs font-bold uppercase text-slate-500">UTM content</dt><dd class="mt-1">{{ $lead->utm_content ?: 'Not recorded' }}</dd></div></dl></section>

            <section class="surface p-5"><h2 class="text-xl font-bold">Consent evidence</h2><div class="mt-4 grid gap-4 md:grid-cols-2"><div class="border border-slate-200 p-4"><h3 class="font-bold">Contact consent</h3><p class="mt-2 {{ $lead->contact_consent_at ? 'text-emerald-800' : 'text-slate-600' }}">{{ $lead->contact_consent_at ? 'Confirmed' : 'Not recorded' }}</p><p class="mt-2 text-sm text-slate-600">{{ $lead->contact_consent_at?->timezone($activeOrganization->timezone)->format('M j, Y g:i A') ?? 'No timestamp' }}</p><p class="mt-1 text-xs text-slate-500">Version: {{ $lead->contact_consent_version ?: 'Not recorded' }}</p></div><div class="border border-slate-200 p-4"><h3 class="font-bold">SMS consent</h3><p class="mt-2 {{ $lead->sms_consent_at ? 'text-emerald-800' : 'text-slate-600' }}">{{ $lead->sms_consent_at ? 'Confirmed separately' : 'Not provided' }}</p><p class="mt-2 text-sm text-slate-600">{{ $lead->sms_consent_at?->timezone($activeOrganization->timezone)->format('M j, Y g:i A') ?? 'No timestamp' }}</p><p class="mt-1 text-xs text-slate-500">Version: {{ $lead->sms_consent_version ?: 'Not recorded' }}</p></div></div></section>
        </div>

        <aside class="space-y-5">
            <section class="surface p-5"><h2 class="text-lg font-bold">Contact</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="font-bold text-slate-500">Name</dt><dd>{{ $lead->first_name }} {{ $lead->last_name }}</dd></div><div><dt class="font-bold text-slate-500">Company</dt><dd>{{ $lead->company ?: 'Not provided' }}</dd></div><div><dt class="font-bold text-slate-500">Email</dt><dd class="break-all"><a class="text-brand-blue-dark underline" href="mailto:{{ $lead->email }}">{{ $lead->email }}</a></dd></div><div><dt class="font-bold text-slate-500">Phone</dt><dd><a class="text-brand-blue-dark underline" href="tel:{{ $lead->phone }}">{{ $lead->phone }}</a></dd></div><div><dt class="font-bold text-slate-500">ZIP</dt><dd>{{ $lead->zip }}</dd></div><div><dt class="font-bold text-slate-500">Received</dt><dd>{{ $lead->received_at->timezone($activeOrganization->timezone)->format('M j, Y g:i A') }}</dd></div></dl></section>

            @can('convert',$lead)
                <section class="surface p-5"><h2 class="text-lg font-bold">Actions</h2><div class="mt-4 space-y-3">
                    @if($lead->status==='received')
                        <form method="POST" action="{{ route('office.leads.convert',$lead) }}" data-offline-write>@csrf<button class="button-primary w-full">Convert to Opportunity</button></form>
                        <form method="POST" action="{{ route('office.leads.archive',$lead) }}" data-offline-write>@csrf<button class="button-secondary w-full">Archive</button></form>
                        <form method="POST" action="{{ route('office.leads.spam',$lead) }}" data-offline-write>@csrf<button class="button-secondary w-full">Mark spam</button></form>
                    @elseif(in_array($lead->status,['spam','archived'],true))
                        <form method="POST" action="{{ route('office.leads.reopen',$lead) }}" data-offline-write>@csrf<button class="button-primary w-full">Reopen lead</button></form>
                    @elseif($lead->status==='converted' && $lead->opportunity)
                        <a class="button-primary w-full" href="{{ route('office.opportunities.show',$lead->opportunity) }}">Open Opportunity</a>
                    @endif
                </div></section>
            @endcan
        </aside>
    </div>
</x-layouts.office>
