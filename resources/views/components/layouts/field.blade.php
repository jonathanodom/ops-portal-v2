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
    <div data-connectivity-banner hidden class="bg-amber-100 px-4 py-2 text-center text-sm font-bold text-amber-900" role="status">
        You’re offline. No changes will be marked saved.
    </div>
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3">
        <div class="mx-auto flex max-w-2xl items-center justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Field workspace</p>
                <p class="mt-0.5 text-sm font-bold text-slate-950">{{ auth()->user()->name }}</p>
            </div>
            <img src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech LLC" class="w-32">
        </div>
    </header>
    <main class="mx-auto max-w-2xl p-4 sm:p-6">{{ $slot }}</main>
    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-3 pb-[max(.75rem,env(safe-area-inset-bottom))] pt-2" aria-label="Field">
        <div class="mx-auto grid max-w-2xl grid-cols-2 gap-2">
            <a href="{{ route('field.home') }}" aria-current="page" class="flex min-h-14 flex-col items-center justify-center rounded-lg bg-blue-50 text-xs font-bold text-brand-blue-dark">
                <span class="text-lg" aria-hidden="true">●</span> Today
            </a>
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
