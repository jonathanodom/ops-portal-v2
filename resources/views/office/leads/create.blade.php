<x-layouts.office title="New Lead" width="form">
    <x-office.record-header title="New Lead" :back-href="route('office.leads.index')" back-label="Leads" description="Record a phone, walk-in, referral, or other manually received inquiry." />

    @if($errors->any())
        <div class="mt-5 border border-red-300 bg-red-50 p-4 text-red-900" role="alert">
            <p class="font-bold">Lead needs attention</p>
            <p class="mt-1 text-sm">Correct the highlighted fields and try again.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('office.leads.store') }}" class="surface mt-6 space-y-6 p-5 sm:p-6" data-offline-write>
        @csrf
        <fieldset>
            <legend class="text-lg font-bold text-slate-950">Contact</legend>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label class="form-label" for="first_name">First name</label><input class="form-input @error('first_name') border-red-500 @enderror" id="first_name" name="first_name" value="{{ old('first_name') }}" autocomplete="given-name" required><x-field-error field="first_name" /></div>
                <div><label class="form-label" for="last_name">Last name</label><input class="form-input @error('last_name') border-red-500 @enderror" id="last_name" name="last_name" value="{{ old('last_name') }}" autocomplete="family-name" required><x-field-error field="last_name" /></div>
                <div><label class="form-label" for="phone">Phone</label><input class="form-input @error('phone') border-red-500 @enderror" id="phone" name="phone" value="{{ old('phone') }}" type="tel" autocomplete="tel" required><x-field-error field="phone" /></div>
                <div><label class="form-label" for="email">Email</label><input class="form-input @error('email') border-red-500 @enderror" id="email" name="email" value="{{ old('email') }}" type="email" autocomplete="email" required><x-field-error field="email" /></div>
                <div><label class="form-label" for="customer_type">Customer type</label><select class="form-input @error('customer_type') border-red-500 @enderror" id="customer_type" name="customer_type" required><option value="">Choose type</option>@foreach(['Individual','Business'] as $type)<option value="{{ $type }}" @selected(old('customer_type')===$type)>{{ $type }}</option>@endforeach</select><x-field-error field="customer_type" /></div>
                <div><label class="form-label" for="company">Company <span class="font-normal text-slate-500">(required for Business)</span></label><input class="form-input @error('company') border-red-500 @enderror" id="company" name="company" value="{{ old('company') }}" autocomplete="organization"><x-field-error field="company" /></div>
                <div><label class="form-label" for="zip">ZIP</label><input class="form-input @error('zip') border-red-500 @enderror" id="zip" name="zip" value="{{ old('zip') }}" inputmode="numeric" autocomplete="postal-code" required><x-field-error field="zip" /></div>
                <div><label class="form-label" for="preferred_contact">Preferred contact</label><select class="form-input @error('preferred_contact') border-red-500 @enderror" id="preferred_contact" name="preferred_contact" required><option value="">Choose method</option>@foreach(['Phone','Text','Email'] as $method)<option value="{{ $method }}" @selected(old('preferred_contact')===$method)>{{ $method }}</option>@endforeach</select><x-field-error field="preferred_contact" /></div>
            </div>
        </fieldset>

        <fieldset class="border-t border-slate-200 pt-6">
            <legend class="text-lg font-bold text-slate-950">Inquiry</legend>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label class="form-label" for="service_interest">Service interest</label><select class="form-input @error('service_interest') border-red-500 @enderror" id="service_interest" name="service_interest" required><option value="">Choose service</option>@foreach($serviceInterests as $interest)<option value="{{ $interest }}" @selected(old('service_interest')===$interest)>{{ $interest }}</option>@endforeach</select><x-field-error field="service_interest" /></div>
                <div><label class="form-label" for="selected_plan">Selected plan <span class="font-normal text-slate-500">(optional)</span></label><input class="form-input @error('selected_plan') border-red-500 @enderror" id="selected_plan" name="selected_plan" value="{{ old('selected_plan') }}"><x-field-error field="selected_plan" /></div>
                <div class="sm:col-span-2"><label class="form-label" for="timeline">Timeline <span class="font-normal text-slate-500">(optional)</span></label><input class="form-input @error('timeline') border-red-500 @enderror" id="timeline" name="timeline" value="{{ old('timeline') }}" placeholder="For example, within 30 days"><x-field-error field="timeline" /></div>
                <div class="sm:col-span-2"><label class="form-label" for="details">Details</label><textarea class="form-textarea @error('details') border-red-500 @enderror" id="details" name="details" required>{{ old('details') }}</textarea><x-field-error field="details" /></div>
            </div>
        </fieldset>

        <fieldset class="border-t border-slate-200 pt-6">
            <legend class="text-lg font-bold text-slate-950">Consent confirmation</legend>
            <p class="mt-2 text-sm text-slate-600">Check only permissions the customer explicitly confirmed. Choosing Text above does not confirm SMS consent.</p>
            <div class="mt-4 space-y-3">
                <label class="flex min-h-11 items-start gap-3 border border-slate-300 p-3"><input class="mt-1" type="checkbox" name="contact_consent" value="1" @checked(old('contact_consent'))><span><span class="font-bold">General contact permission confirmed</span><span class="mt-1 block text-sm text-slate-600">The customer gave permission to be contacted about this inquiry.</span></span></label>
                <label class="flex min-h-11 items-start gap-3 border border-slate-300 p-3"><input class="mt-1" type="checkbox" name="sms_consent" value="1" @checked(old('sms_consent'))><span><span class="font-bold">SMS consent confirmed separately</span><span class="mt-1 block text-sm text-slate-600">The customer explicitly agreed to receive text messages.</span></span></label>
            </div>
        </fieldset>

        <div class="flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-end"><a class="button-secondary" href="{{ route('office.leads.index') }}">Cancel</a><button class="button-primary">Create lead</button></div>
    </form>
</x-layouts.office>
