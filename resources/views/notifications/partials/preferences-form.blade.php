<div class="space-y-5">
    @if(session('status'))<x-office.alert type="success">{{ session('status') }}</x-office.alert>@endif
    <header class="border-b border-slate-300 pb-4">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Account</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-950">Notification preferences</h1>
        <p class="mt-1 text-sm text-slate-600">Choose how operational updates reach you. In-app notifications always remain available.</p>
    </header>

    <form method="POST" action="{{ route('notifications.preferences.update') }}" class="border border-slate-300 bg-white">
        @csrf
        @method('PUT')
        <div class="hidden border-b border-slate-200 px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate-500 sm:grid sm:grid-cols-[minmax(0,1fr)_7rem_7rem_7rem] sm:gap-3">
            <span>Category</span><span>In app</span><span>Email</span><span>Browser</span>
        </div>
        <div class="divide-y divide-slate-200">
            @foreach($preferenceCategories as $key => $category)
                <fieldset class="grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_7rem_7rem_7rem] sm:items-center sm:gap-3">
                    <legend class="sr-only">{{ $category['label'] }}</legend>
                    <div>
                        <p class="font-bold text-slate-950">{{ $category['label'] }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $category['description'] }}</p>
                    </div>
                    <div class="flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-700">
                        <span class="inline-flex h-5 w-5 items-center justify-center bg-emerald-100 text-emerald-800" aria-hidden="true">✓</span>
                        <span>Always on</span>
                    </div>
                    <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="preferences[{{ $key }}][email]" value="0">
                        <input type="checkbox" name="preferences[{{ $key }}][email]" value="1" class="h-5 w-5 border-slate-400 text-brand-blue focus:ring-brand-blue" @checked(old("preferences.{$key}.email", $category['email_enabled']))>
                        Email
                    </label>
                    <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-800">
                        <input type="hidden" name="preferences[{{ $key }}][push]" value="0">
                        <input type="checkbox" name="preferences[{{ $key }}][push]" value="1" class="h-5 w-5 border-slate-400 text-brand-blue focus:ring-brand-blue" @checked(old("preferences.{$key}.push", $category['push_enabled']))>
                        Browser
                    </label>
                </fieldset>
            @endforeach
        </div>
        @if($errors->any())
            <div class="border-t border-red-300 bg-red-50 px-5 py-3 text-sm font-semibold text-red-900" role="alert">Check the notification choices and try again.</div>
        @endif
        <div class="flex flex-wrap justify-between gap-3 border-t border-slate-300 bg-slate-50 p-5">
            <a href="{{ route('notifications.index') }}" class="button-secondary">Back to notifications</a>
            <button class="button-primary">Save preferences</button>
        </div>
    </form>
</div>
