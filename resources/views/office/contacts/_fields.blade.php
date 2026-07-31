<div class="grid gap-5 sm:grid-cols-2">
    <div><label for="name" class="form-label">Name</label><input id="name" name="name" class="form-input" value="{{ old('name', $contact?->name) }}" required></div>
    <div><label for="role" class="form-label">Role</label><input id="role" name="role" class="form-input" value="{{ old('role', $contact?->role) }}"></div>
    <div><label for="phone" class="form-label">Phone</label><input id="phone" name="phone" type="tel" class="form-input" value="{{ old('phone', $contact?->phone) }}"></div>
    <div><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" class="form-input" value="{{ old('email', $contact?->email) }}"></div>
</div>
<div class="mt-5 flex flex-col gap-3 sm:flex-row sm:gap-8">
    <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-700"><input type="hidden" name="is_preferred" value="0"><input type="checkbox" name="is_preferred" value="1" class="size-5" @checked(old('is_preferred', $contact?->is_preferred ?? false))> Preferred customer contact</label>
    <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-700"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="size-5" @checked(old('active', $contact?->active ?? true))> Active</label>
</div>
