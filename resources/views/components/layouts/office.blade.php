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
            <img src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech LLC" class="w-48">
            <div class="mt-9 text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Office workspace</div>
            <nav class="mt-3" aria-label="Office">
                <a href="{{ route('office.home') }}" @if(request()->routeIs('office.home')) aria-current="page" @endif class="flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.home') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Overview</a>
                @if ($activeMembership->hasCapability('customers.view'))
                    <a href="{{ route('office.customers.index') }}" @if(request()->routeIs('office.customers.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.customers.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Customers</a>
                    <a href="{{ route('office.locations.index') }}" @if(request()->routeIs('office.locations.*')) aria-current="page" @endif class="mt-1 flex min-h-11 items-center rounded-lg border-l-4 px-4 text-sm font-bold {{ request()->routeIs('office.locations.*') ? 'border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">Service locations</a>
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
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/newday-logo.png') }}" alt="" class="w-32 lg:hidden">
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
            @if ($activeMembership->hasCapability('customers.view'))
                <nav class="flex gap-2 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:hidden" aria-label="Office mobile">
                    <a href="{{ route('office.home') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.home') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Overview</a>
                    <a href="{{ route('office.customers.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.customers.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Customers</a>
                    <a href="{{ route('office.locations.index') }}" class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ request()->routeIs('office.locations.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">Locations</a>
                </nav>
            @endif
            <main id="main-content" class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
