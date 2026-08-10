<x-settings-layout title="Organization settings">
    <div><h2 class="text-2xl font-bold">Organization</h2><p class="mt-2 text-slate-600">Canonical business identity, operational timezone, and portal branding.</p></div>
    <form method="POST" action="{{ route('office.settings.organization.update') }}" class="surface mt-6 p-5" data-offline-write>@csrf @method('PUT')
        <h3 class="text-xl font-bold">Business profile</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach(['name'=>'Display name','legal_name'=>'Legal name','email'=>'Main email','phone'=>'Main phone','website'=>'Website','address_line_1'=>'Address line 1','address_line_2'=>'Address line 2','city'=>'City','state'=>'State','postal_code'=>'ZIP'] as $field=>$label)
                <div><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-input @error($field) border-danger @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$organization->$field) }}" @if($field==='name') required @endif>@error($field)<p class="form-error">{{ $message }}</p>@enderror</div>
            @endforeach
            <div><label class="form-label" for="country">Country</label><input class="form-input bg-slate-50" id="country" value="United States" disabled></div>
            <div><label class="form-label" for="timezone">Organization timezone</label><select class="form-input @error('timezone') border-danger @enderror" id="timezone" name="timezone" required>@foreach($timezones as $timezone)<option value="{{ $timezone }}" @selected(old('timezone',$organization->timezone)===$timezone)>{{ $timezone }}</option>@endforeach</select>@error('timezone')<p class="form-error">{{ $message }}</p>@enderror</div>
            <label class="md:col-span-2 flex min-h-11 items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3"><input class="mt-1" type="checkbox" name="confirm_timezone_change" value="1"> <span><strong>Confirm timezone changes</strong><span class="block text-sm text-amber-900">Required only when changing timezone. Existing UTC timestamps and Service Location timezones are not rewritten.</span></span></label>
        </div>
        <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm"><strong>System identity</strong><p class="mt-1 text-slate-600">Slug: {{ $organization->slug }} · Status: {{ $organization->active ? 'Active' : 'Inactive' }}. These values are managed outside this page.</p></div>
        <button class="button-primary mt-5">Save organization</button>
    </form>
    <section class="surface mt-6 p-5"><h3 class="text-xl font-bold">Branding</h3><p class="mt-2 text-sm text-slate-600">PNG, JPEG, or WebP; 5 MB maximum; 64–4096 pixels. Uploaded assets remain private.</p>
        <div class="mt-5 grid gap-5 md:grid-cols-2">
            @foreach(['full'=>'Full logo','mark'=>'Compact mark'] as $variant=>$label)<article class="rounded-lg border border-slate-200 p-4"><h4 class="font-bold">{{ $label }}</h4><div class="mt-3 flex h-32 items-center justify-center rounded-lg bg-slate-50 p-4"><x-organization-logo :variant="$variant" class="max-h-24 max-w-full object-contain" /></div><form method="POST" action="{{ route('office.settings.organization.brand.upload',$variant) }}" enctype="multipart/form-data" class="mt-4" data-upload-form>@csrf<label class="form-label" for="logo-{{ $variant }}">Upload {{ strtolower($label) }}</label><input class="form-input" id="logo-{{ $variant }}" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required><button class="button-secondary mt-3 w-full">Upload</button><progress class="mt-2 w-full" max="100" hidden></progress><p class="mt-1 text-sm text-slate-600" data-upload-status aria-live="polite"></p></form>@if($variant==='full' ? $organization->full_logo_asset_id : $organization->mark_logo_asset_id)<form method="POST" action="{{ route('office.settings.organization.brand.remove',$variant) }}" class="mt-3">@csrf @method('DELETE')<button class="button-secondary w-full">Reset to default</button></form>@endif</article>@endforeach
        </div>
    </section>
</x-settings-layout>
