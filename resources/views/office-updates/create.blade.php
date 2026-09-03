<x-layouts.office title="New Office Update" width="form">
    <nav class="text-sm" aria-label="Breadcrumb"><a class="font-bold text-brand-blue" href="{{ route('office-updates.index') }}">← Office Updates</a></nav>
    <div class="mt-4 border-b border-slate-300 pb-4">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Staff</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-950">New Office Update</h1>
        <p class="mt-1 text-sm text-slate-600">Publish a plain-text announcement through in-app, email, and browser push channels where enabled.</p>
    </div>

    @if($errors->any())
        <div class="mt-5 border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">Update needs attention</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form class="mt-5 space-y-5 border border-slate-300 bg-white p-5" method="POST" action="{{ route('office-updates.store') }}" data-offline-write>
        @csrf
        <input type="hidden" name="publish_token" value="{{ old('publish_token', (string) Illuminate\Support\Str::uuid()) }}">
        <div><label class="form-label" for="office-update-title">Title</label><input class="form-input" id="office-update-title" name="title" maxlength="180" value="{{ old('title') }}" required autofocus>@error('title')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
        <div><label class="form-label" for="office-update-body">Message</label><textarea class="form-textarea min-h-40" id="office-update-body" name="body" maxlength="10000" required>{{ old('body') }}</textarea>@error('body')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>

        <fieldset class="border-t border-slate-200 pt-5">
            <legend class="text-base font-bold text-slate-950">Send to</legend>
            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                <label class="flex min-h-11 items-center gap-3 border border-slate-300 px-3 py-2"><input type="radio" name="audience_type" value="all_staff" @checked(old('audience_type', 'all_staff') === 'all_staff')><span><strong class="block">All Staff</strong><span class="text-xs text-slate-500">Every active staff member in this organization.</span></span></label>
                <label class="flex min-h-11 items-center gap-3 border border-slate-300 px-3 py-2"><input type="radio" name="audience_type" value="selected_staff" @checked(old('audience_type') === 'selected_staff')><span><strong class="block">Selected Staff</strong><span class="text-xs text-slate-500">Only the active staff selected below.</span></span></label>
            </div>
            @error('audience_type')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror

            <div class="mt-4 border border-slate-200 p-3">
                <p class="text-sm font-bold text-slate-700">Selected Staff</p>
                <div class="mt-2 grid gap-1 sm:grid-cols-2">
                    @foreach($staff as $membership)
                        <label class="flex min-h-11 items-center gap-3 px-2 py-1 hover:bg-slate-50"><input type="checkbox" name="recipient_user_ids[]" value="{{ $membership->user_id }}" @checked(in_array($membership->user_id, old('recipient_user_ids', [])))><span>{{ $membership->user->name }}</span></label>
                    @endforeach
                </div>
                @error('recipient_user_ids')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
                @error('recipient_user_ids.*')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror
            </div>
        </fieldset>

        <div class="flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end"><a class="button-secondary" href="{{ route('office-updates.index') }}">Cancel</a><button class="button-primary" type="submit">Publish Update</button></div>
    </form>
</x-layouts.office>
