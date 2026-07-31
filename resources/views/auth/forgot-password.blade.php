<x-layouts.auth title="Reset password">
    <p class="text-sm font-bold uppercase tracking-[0.16em] text-brand-blue">Account recovery</p>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Reset your password</h1>
    <p class="mt-2 text-base leading-7 text-slate-600">Enter your staff email and we’ll send reset instructions if the account is active.</p>

    @if (session('status'))
        <div class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf
        <div>
            <label for="email" class="form-label">Email address</label>
            <input id="email" name="email" type="email" autocomplete="email" required autofocus value="{{ old('email') }}" class="form-input">
            @error('email') <p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="button-primary w-full">Send reset link</button>
        <a href="{{ route('login') }}" class="button-secondary w-full">Back to sign in</a>
    </form>
</x-layouts.auth>
