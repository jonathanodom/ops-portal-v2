<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access unavailable | NewDay Tech Ops</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-canvas p-5">
    <main class="surface w-full max-w-lg p-8 text-center">
        <img src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech LLC" class="mx-auto w-48">
        <p class="mt-8 text-sm font-bold uppercase tracking-[0.14em] text-brand-orange">Access unavailable</p>
        <h1 class="mt-3 text-3xl font-bold text-slate-950">This workspace isn’t assigned to you.</h1>
        <p class="mt-3 text-base leading-7 text-slate-600">Your account is signed in, but it does not have the required organization capability.</p>
        <a href="{{ url('/') }}" class="button-primary mt-7">Return home</a>
    </main>
</body>
</html>
