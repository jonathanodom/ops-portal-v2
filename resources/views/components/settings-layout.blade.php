@props(['title'])
<x-layouts.office :title="$title">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">Settings need attention</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p class="text-sm font-bold uppercase tracking-[.12em] text-brand-blue">Administration</p>
    <h1 class="mt-1 text-3xl font-bold">Settings</h1>
    <nav class="mt-6 flex gap-2 overflow-x-auto border-b border-slate-200" aria-label="Settings">
        @if($activeMembership->hasCapability('organization.settings.manage'))<a href="{{ route('office.settings.organization.edit') }}" @if(request()->routeIs('office.settings.organization.*')) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center border-b-2 px-4 text-sm font-bold {{ request()->routeIs('office.settings.organization.*') ? 'border-brand-blue text-brand-blue-dark' : 'border-transparent text-slate-600' }}">Organization</a>@endif
        @if($activeMembership->hasCapability('billing.settings.manage'))<a href="{{ route('office.settings.billing.edit') }}" @if(request()->routeIs('office.settings.billing.*')) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center border-b-2 px-4 text-sm font-bold {{ request()->routeIs('office.settings.billing.*') ? 'border-brand-blue text-brand-blue-dark' : 'border-transparent text-slate-600' }}">Billing</a><a href="{{ route('office.settings.invoices.edit') }}" @if(request()->routeIs('office.settings.invoices.*')) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center border-b-2 px-4 text-sm font-bold {{ request()->routeIs('office.settings.invoices.*') ? 'border-brand-blue text-brand-blue-dark' : 'border-transparent text-slate-600' }}">Invoices</a>@endif
    </nav>
    <div class="mt-6">{{ $slot }}</div>
</x-layouts.office>
