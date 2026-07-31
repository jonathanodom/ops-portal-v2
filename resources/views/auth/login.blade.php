<x-layouts.auth title="Staff sign in">
    <p class="text-sm font-bold uppercase tracking-[0.16em] text-brand-blue">NewDay Ops Portal</p>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Welcome back</h1>
    <p class="mt-2 text-base leading-7 text-slate-600">Sign in with your NewDay Tech staff account.</p>

    @if (session('status'))
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="email" class="form-label">Email address</label>
            <input id="email" name="email" type="email" autocomplete="username" required autofocus
                   value="{{ old('email') }}" class="form-input" placeholder="you@newdaytech.net">
            @error('email') <p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <div class="mb-2 flex items-center justify-between gap-4">
                <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                <a href="{{ route('password.request') }}" class="text-sm font-semibold text-brand-blue hover:text-brand-blue-dark">Forgot password?</a>
            </div>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="form-input">
            @error('password') <p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="button-primary w-full">Sign in</button>
    </form>
    <p class="mt-8 text-center text-sm text-slate-500">Authorized NewDay Tech staff only.</p>
</x-layouts.auth>
