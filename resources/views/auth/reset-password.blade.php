<x-layouts.auth title="Choose a new password">
    <p class="text-sm font-bold uppercase tracking-[0.16em] text-brand-blue">Account recovery</p>
    <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950">Choose a new password</h1>
    <p class="mt-2 text-base leading-7 text-slate-600">Use at least 12 characters and avoid passwords used elsewhere.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="form-label">Email address</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $email) }}" class="form-input">
            @error('email') <p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password" class="form-label">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="form-input">
            @error('password') <p class="mt-2 text-sm font-medium text-red-700" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="form-input">
        </div>
        <button type="submit" class="button-primary w-full">Update password</button>
    </form>
</x-layouts.auth>
