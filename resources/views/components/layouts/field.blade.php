<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1D80F7">
    <title>{{ $title ?? 'Field' }} | NewDay Tech Ops</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas pb-24">
    <div data-connectivity-banner hidden class="sticky top-0 z-30 border-b border-amber-300 bg-amber-100 px-4 py-3 text-center text-sm font-bold text-amber-950" role="alert">
        You’re offline. Writes and uploads are disabled; reconnect, then retry.
    </div>
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3">
        <div class="mx-auto flex max-w-2xl items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Field workspace</p>
                <p class="mt-0.5 truncate text-sm font-bold text-slate-950">{{ auth()->user()->name }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2 sm:gap-3">
                @if ($activeMembership->hasCapability('experience.office.access'))
                    <a href="{{ route('office.home') }}" class="button-secondary px-3 text-xs" aria-label="Return to office view">Office view</a>
                @endif
                <p data-connectivity-status class="inline-flex min-h-11 items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 px-3 text-xs font-bold text-emerald-800" role="status" aria-live="polite">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-600" aria-hidden="true"></span>
                    <span data-connectivity-label>Online</span>
                </p>
                <x-organization-logo variant="mark" class="h-11 w-11 object-contain" />
            </div>
        </div>
    </header>
    <main id="main-content" class="mx-auto max-w-2xl p-4 sm:p-6">{{ $slot }}</main>
    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-3 pb-[max(.75rem,env(safe-area-inset-bottom))] pt-2" aria-label="Field">
        <div class="mx-auto grid max-w-2xl {{ $activeMembership->hasCapability('customers.view') ? 'grid-cols-3' : 'grid-cols-2' }} gap-2">
            <a href="{{ route('field.home') }}" @if(request()->routeIs('field.home') || request()->routeIs('field.visits.*')) aria-current="page" @endif class="flex min-h-14 flex-col items-center justify-center rounded-lg text-xs font-bold {{ request()->routeIs('field.home') || request()->routeIs('field.visits.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600 hover:bg-slate-50' }}">
                <span class="text-lg" aria-hidden="true">●</span> Today
            </a>
            @if ($activeMembership->hasCapability('customers.view'))
                <a href="{{ route('field.customers.index') }}" @if(request()->routeIs('field.customers.*') || request()->routeIs('field.locations.*')) aria-current="page" @endif class="flex min-h-14 flex-col items-center justify-center rounded-lg text-xs font-bold {{ request()->routeIs('field.customers.*') || request()->routeIs('field.locations.*') ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600 hover:bg-slate-50' }}">
                    <span class="text-lg" aria-hidden="true">◎</span> Customers
                </a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="flex min-h-14 w-full flex-col items-center justify-center rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-50">
                    <span class="text-lg" aria-hidden="true">↪</span> Sign out
                </button>
            </form>
        </div>
    </nav>
</body>
</html>
