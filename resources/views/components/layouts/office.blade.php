@props(['title' => 'Office', 'width' => 'default'])
@php
    $contentWidthClass = match ($width) {
        'workspace' => 'w-full max-w-none',
        'detail' => 'mx-auto w-full max-w-[1600px]',
        'form' => 'mx-auto w-full max-w-4xl',
        default => 'mx-auto w-full max-w-7xl',
    };
    $customerWorkspaceActive = request()->routeIs('office.customers.*') || request()->routeIs('office.locations.*');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D80F7">
    <title>{{ $title ?? 'Office' }} | NewDay Tech Ops</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas">
    <div data-connectivity-banner hidden class="bg-amber-100 px-4 py-2 text-center text-sm font-bold text-amber-900" role="status">
        You’re offline. Changes cannot be saved until connectivity returns.
    </div>
    <div class="min-h-screen lg:grid lg:grid-cols-[248px_minmax(0,1fr)]">
        <aside class="hidden border-r border-slate-200 bg-white p-5 lg:flex lg:flex-col">
            <x-organization-logo variant="full" class="max-h-20 w-48 object-contain object-left" />
            <div class="mt-9 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Office workspace</div>
            <nav class="mt-3" aria-label="Office">
                <a href="{{ route('office.home') }}" @if(request()->routeIs('office.home')) aria-current="page" @endif class="flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.home') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Dashboard</a>
                @if ($activeMembership->hasCapability('customers.view'))
                    <a href="{{ route('office.customers.index') }}" data-office-primary-customers @if($customerWorkspaceActive) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ $customerWorkspaceActive ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Customers</a>
                @endif
                @if ($activeMembership->hasCapability('service_tickets.view'))
                    <a href="{{ route('office.service-tickets.index') }}" @if(request()->routeIs('office.service-tickets.*') || request()->routeIs('office.visits.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.service-tickets.*') || request()->routeIs('office.visits.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Service Tickets</a>
                    <a href="{{ route('office.dispatch.index') }}" @if(request()->routeIs('office.dispatch.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.dispatch.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Dispatch</a>
                @endif
                @if ($activeMembership->hasCapability('catalog.view') || $activeMembership->hasCapability('subscriptions.view'))
                    <a href="{{ $activeMembership->hasCapability('catalog.view') ? route('office.catalog.services.index') : route('office.subscriptions.index') }}" @if(request()->routeIs('office.catalog.*') || request()->routeIs('office.subscriptions.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.catalog.*') || request()->routeIs('office.subscriptions.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Catalog</a>
                @endif
                @if ($activeMembership->hasCapability('closeouts.inspect'))
                    <a href="{{ route('office.closeout-reviews.index') }}" @if(request()->routeIs('office.closeout-reviews.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.closeout-reviews.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Review</a>
                @endif
                @if ($activeMembership->hasCapability('billing_handoffs.view') || $activeMembership->hasCapability('invoices.view'))
                    <a href="{{ $activeMembership->hasCapability('invoices.view') ? route('office.invoices.index') : route('office.billing-handoffs.index') }}" @if(request()->routeIs('office.billing-handoffs.*') || request()->routeIs('office.invoices.*') || request()->routeIs('office.billing.settings.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.billing-handoffs.*') || request()->routeIs('office.invoices.*') || request()->routeIs('office.billing.settings.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Billing</a>
                @endif
                @if ($activeMembership->hasCapability('operations.health.view'))
                    <a href="{{ route('office.operations.health') }}" @if(request()->routeIs('office.operations.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.operations.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Health</a>
                @endif
                @if ($activeMembership->hasCapability('visits.archive.manage'))
                    <a href="{{ route('office.admin.archive.index') }}" @if(request()->routeIs('office.admin.archive.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.admin.archive.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Admin Archive</a>
                @endif
                @if ($activeMembership->hasCapability('organization.settings.manage') || $activeMembership->hasCapability('billing.settings.manage') || $activeMembership->hasCapability('payments.view'))
                    <a href="{{ route('office.settings.index') }}" @if(request()->routeIs('office.settings.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.settings.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Settings</a>
                @endif
            </nav>
            <div class="mt-auto border-t border-slate-200 pt-5">
                <p class="text-sm font-bold text-slate-900">{{ auth()->user()->name }}</p>
                <p class="mt-1 truncate text-xs text-slate-500">{{ $activeOrganization->name }}</p>
                <form method="POST" action="{{ route('logout') }}" class="mt-4">
                    @csrf
                    <button class="button-secondary w-full">Sign out</button>
                </form>
            </div>
        </aside>

        <div>
            <header class="border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-8">
                <div class="{{ $contentWidthClass }} flex items-center justify-between gap-4" data-office-header-width="{{ $width }}">
                    <div class="flex items-center gap-3">
                        <x-organization-logo variant="mark" class="h-10 w-10 object-contain lg:hidden" />
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Office</p>
                            <p class="text-sm font-semibold text-slate-600">{{ $activeOrganization->name }}</p>
                        </div>
                    </div>
                    @if ($activeMembership->hasCapability('experience.field.access'))
                        <a href="{{ route('field.home') }}" class="button-secondary">Open field view</a>
                    @endif
                </div>
            </header>
            <nav class="office-mobile-primary-nav flex gap-2 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:hidden" aria-label="Office mobile">
                    <a href="{{ route('office.home') }}" @if(request()->routeIs('office.home')) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.home') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Dashboard</a>
                    @if ($activeMembership->hasCapability('customers.view'))
                    <a href="{{ route('office.customers.index') }}" data-office-primary-customers @if($customerWorkspaceActive) aria-current="page" @endif class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ $customerWorkspaceActive ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Customers</a>
                    @endif
                    @if($activeMembership->hasCapability('service_tickets.view'))<a href="{{ route('office.service-tickets.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.service-tickets.*') || request()->routeIs('office.visits.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Tickets</a><a href="{{ route('office.dispatch.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.dispatch.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Dispatch</a>@endif
                    @if($activeMembership->hasCapability('catalog.view') || $activeMembership->hasCapability('subscriptions.view'))<a href="{{ $activeMembership->hasCapability('catalog.view') ? route('office.catalog.services.index') : route('office.subscriptions.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.catalog.*') || request()->routeIs('office.subscriptions.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Catalog</a>@endif
                    @if($activeMembership->hasCapability('closeouts.inspect'))<a href="{{ route('office.closeout-reviews.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.closeout-reviews.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Review</a>@endif
                    @if($activeMembership->hasCapability('billing_handoffs.view') || $activeMembership->hasCapability('invoices.view'))<a href="{{ $activeMembership->hasCapability('invoices.view') ? route('office.invoices.index') : route('office.billing-handoffs.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.billing-handoffs.*') || request()->routeIs('office.invoices.*') || request()->routeIs('office.billing.settings.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Billing</a>@endif
                    @if($activeMembership->hasCapability('operations.health.view'))<a href="{{ route('office.operations.health') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.operations.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Health</a>@endif
                    @if($activeMembership->hasCapability('visits.archive.manage'))<a href="{{ route('office.admin.archive.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.admin.archive.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Archive</a>@endif
                    @if($activeMembership->hasCapability('organization.settings.manage') || $activeMembership->hasCapability('billing.settings.manage') || $activeMembership->hasCapability('payments.view'))<a href="{{ route('office.settings.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.settings.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Settings</a>@endif
            </nav>
            <main id="main-content" class="{{ $contentWidthClass }} p-4 sm:p-6 lg:p-8" data-office-width="{{ $width }}">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
