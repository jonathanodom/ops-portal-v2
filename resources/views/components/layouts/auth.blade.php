<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D80F7">
    <title>{{ $title ?? 'Staff sign in' }} | NewDay Tech Ops</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas">
    <main class="grid min-h-screen lg:grid-cols-[minmax(0,1fr)_minmax(420px,560px)]">
        <section class="hidden bg-slate-950 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <img src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech LLC" class="w-64 rounded-lg bg-white p-4">
            <div class="max-w-xl">
                <p class="mb-4 text-sm font-bold uppercase tracking-[0.18em] text-brand-orange">Field service foundation</p>
                <h1 class="text-5xl font-bold leading-tight">One clear path from dispatch to closeout.</h1>
                <p class="mt-6 max-w-lg text-lg leading-8 text-slate-300">A focused operations workspace for NewDay Tech office and field teams.</p>
            </div>
            <p class="text-sm text-slate-400">Connected. Protected. Simple.</p>
        </section>

        <section class="flex items-center justify-center px-5 py-10 sm:px-10">
            <div class="w-full max-w-md">
                <img src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech LLC" class="mb-10 w-56 lg:hidden">
                {{ $slot }}
            </div>
        </section>
    </main>
</body>
</html>
