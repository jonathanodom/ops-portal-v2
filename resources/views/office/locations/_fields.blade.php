@php($prefix = ($nested ?? false) ? 'location' : null)
@php($field = fn($name) => $prefix ? "location[$name]" : $name)
@php($key = fn($name) => $prefix ? "location.$name" : $name)
<div class="mt-5 grid gap-5 sm:grid-cols-2">
    <div><label for="location_name" class="form-label">Location name</label><input id="location_name" name="{{ $field('name') }}" class="form-input" value="{{ old($key('name'), $location?->name ?? 'Primary service location') }}" required></div>
    @if(!$prefix)<div><label for="primary_contact_id" class="form-label">Primary contact</label><select id="primary_contact_id" name="primary_contact_id" class="form-input"><option value="">No location contact</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}" @selected((string) old('primary_contact_id', $location?->primary_contact_id) === (string) $contact->id)>{{ $contact->name }}</option>@endforeach</select></div>@endif
    <div><label for="address_line_1" class="form-label">Address line 1</label><input id="address_line_1" name="{{ $field('address_line_1') }}" class="form-input" value="{{ old($key('address_line_1'), $location?->address_line_1) }}" required></div>
    <div><label for="address_line_2" class="form-label">Address line 2</label><input id="address_line_2" name="{{ $field('address_line_2') }}" class="form-input" value="{{ old($key('address_line_2'), $location?->address_line_2) }}"></div>
    <div><label for="city" class="form-label">City</label><input id="city" name="{{ $field('city') }}" class="form-input" value="{{ old($key('city'), $location?->city) }}" required></div>
    <div class="grid grid-cols-2 gap-3">
        <div><label for="state" class="form-label">State</label><select id="state" name="{{ $field('state') }}" class="form-input" required><option value="">Select</option>@foreach($states as $state)<option value="{{ $state }}" @selected(old($key('state'), $location?->state ?? 'TX') === $state)>{{ $state }}</option>@endforeach</select></div>
        <div><label for="postal_code" class="form-label">ZIP code</label><input id="postal_code" name="{{ $field('postal_code') }}" inputmode="numeric" class="form-input" value="{{ old($key('postal_code'), $location?->postal_code) }}" required></div>
    </div>
    <div><label for="timezone" class="form-label">Timezone</label><input id="timezone" name="{{ $field('timezone') }}" class="form-input" value="{{ old($key('timezone'), $location?->timezone ?? $defaultTimezone) }}" required></div>
    <div class="sm:col-span-2"><label for="access_instructions" class="form-label">Field access instructions</label><textarea id="access_instructions" name="{{ $field('access_instructions') }}" class="form-textarea">{{ old($key('access_instructions'), $location?->access_instructions) }}</textarea><p class="mt-2 text-xs text-slate-500">Visible to authorized field users.</p></div>
    <div class="sm:col-span-2"><label for="site_notes" class="form-label">Office-only site notes</label><textarea id="site_notes" name="{{ $field('site_notes') }}" class="form-textarea">{{ old($key('site_notes'), $location?->site_notes) }}</textarea></div>
</div>
@if(!$prefix)
    <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:gap-8">
        <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-700"><input type="hidden" name="is_primary" value="0"><input type="checkbox" name="is_primary" value="1" class="size-5" @checked(old('is_primary', $location?->is_primary ?? false))> Primary service location</label>
        <label class="flex min-h-11 items-center gap-3 text-sm font-semibold text-slate-700"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" class="size-5" @checked(old('active', $location?->active ?? true))> Active</label>
    </div>
@endif
