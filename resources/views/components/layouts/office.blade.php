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
                <a href="{{ route('office.home') }}" aria-current="page" class="flex min-h-11 items-center rounded-lg border-l-4 border-brand-blue bg-blue-50 px-4 text-sm font-bold text-brand-blue-dark">Overview</a>
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
            <main class="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
