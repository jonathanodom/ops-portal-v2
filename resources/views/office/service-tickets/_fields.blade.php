@if($projectContext ?? false)
    <section class="rounded-lg border border-slate-200 bg-slate-50 p-4 sm:p-5" aria-labelledby="project-customer-heading">
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <p id="project-customer-heading" class="form-label">Customer</p>
                <p class="flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-3 font-semibold text-slate-950">{{ $projectCustomer->displayName }}</p>
                <p class="mt-2 text-sm text-slate-600">Fixed by {{ $project->project_number }}.</p>
                @error('customer_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="form-label" for="service_location_id">Service location</label>
                <select class="form-input" id="service_location_id" name="service_location_id" required>
                    <option value="">Select location</option>
                    @foreach($projectLocations as $location)
                        <option value="{{ $location->id }}" @selected((string) old('service_location_id', $project->service_location_id) === (string) $location->id)>{{ $location->name }} — {{ $location->address }}</option>
                    @endforeach
                </select>
                @error('service_location_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="form-label" for="contact_id">Designated contact <span class="font-normal text-slate-500">(optional)</span></label>
                <select class="form-input" id="contact_id" name="contact_id">
                    <option value="">Use location/customer default</option>
                    @foreach($projectContacts as $contact)
                        <option value="{{ $contact->id }}" @selected((string) old('contact_id', $project->primary_contact_id) === (string) $contact->id)>{{ $contact->name }}{{ $contact->role ? ' — '.$contact->role : '' }}</option>
                    @endforeach
                </select>
                @error('contact_id')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="form-label" for="title">Ticket title</label>
                <input class="form-input" id="title" name="title" value="{{ old('title') }}" required maxlength="255">
            </div>
            <div>
                <label class="form-label" for="priority">Priority</label>
                <select class="form-input" id="priority" name="priority" required>@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label class="form-label" for="source">Source</label>
                <select class="form-input" id="source" name="source" required>@foreach($sources as $value => $label)<option value="{{ $value }}" @selected(old('source', 'internal') === $value)>{{ $label }}</option>@endforeach</select>
            </div>
        </div>
        @if($project->service_location_id)
            <label class="mt-4 flex min-h-11 items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 text-sm font-semibold text-amber-950">
                <input type="checkbox" name="confirm_location_mismatch" value="1" @checked(old('confirm_location_mismatch'))>
                Confirm if this Service Ticket uses a different location than the Project.
            </label>
            @error('confirm_location_mismatch')<p class="mt-2 text-sm font-semibold text-red-700" role="alert">{{ $message }}</p>@enderror
        @endif
    </section>
@elseif($customerPicker ?? false)
    @include('office.service-tickets._customer-picker')
@else
<div class="grid gap-5 md:grid-cols-2">
    <div>
        <label class="form-label" for="customer_id">Customer</label>
        <select class="form-input" id="customer_id" name="customer_id" required>
            <option value="">Select customer</option>
            @foreach(($customers ?? collect([$ticket->customer])) as $customer)
                <option value="{{ $customer->id }}" @selected((string) old('customer_id', $ticket->customer_id ?? '') === (string) $customer->id)>{{ $customer->display_name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="service_location_id">Service location</label>
        <select class="form-input" id="service_location_id" name="service_location_id" required>
            <option value="">Select location</option>
            @foreach(($customers ?? collect([$ticket->customer])) as $customer)
                @foreach($customer->serviceLocations as $location)
                    <option value="{{ $location->id }}" data-customer="{{ $customer->id }}" @selected((string) old('service_location_id', $ticket->service_location_id ?? '') === (string) $location->id)>{{ $customer->display_name }} — {{ $location->name }}</option>
                @endforeach
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="contact_id">Designated contact <span class="font-normal text-slate-500">(optional)</span></label>
        <select class="form-input" id="contact_id" name="contact_id">
            <option value="">Use location/customer default</option>
            @foreach(($customers ?? collect([$ticket->customer])) as $customer)
                @foreach($customer->contacts as $contact)
                    <option value="{{ $contact->id }}" data-customer="{{ $customer->id }}" @selected((string) old('contact_id', $ticket->contact_id ?? '') === (string) $contact->id)>{{ $customer->display_name }} — {{ $contact->name }}</option>
                @endforeach
            @endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="title">Ticket title</label>
        <input class="form-input" id="title" name="title" value="{{ old('title', $ticket->title ?? '') }}" required maxlength="255">
    </div>
    <div>
        <label class="form-label" for="priority">Priority</label>
        <select class="form-input" id="priority" name="priority" required>
            @foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(old('priority', $ticket->priority ?? 'normal') === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="source">Source</label>
        <select class="form-input" id="source" name="source" required>
            @foreach($sources as $value => $label)<option value="{{ $value }}" @selected(old('source', $ticket->source ?? 'internal') === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
</div>
@endif
<div class="mt-5 grid gap-5 md:grid-cols-2">
    <div>
        <label class="form-label" for="purpose">Ticket purpose</label>
        <select class="form-input" id="purpose" name="purpose" required>
            @foreach($purposes as $value => $label)<option value="{{ $value }}" @selected(old('purpose', $ticket->purpose ?? 'service_call') === $value)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="form-label" for="billing_disposition">Billing disposition</label>
        <select class="form-input" id="billing_disposition" name="billing_disposition" required>
            @foreach($billingDispositions as $value => $label)<option value="{{ $value }}" @selected(old('billing_disposition', $ticket->billing_disposition ?? 'billable') === $value)>{{ $label }}</option>@endforeach
        </select>
        <p class="mt-1 text-xs text-slate-500">Non-billable tickets complete operationally without creating a Billing Handoff or invoice.</p>
    </div>
</div>
<div class="mt-5">
    <label class="form-label" for="description">Work scope</label>
    <textarea class="form-textarea" id="description" name="description" maxlength="10000">{{ old('description', $ticket->description ?? '') }}</textarea>
</div>
<div class="mt-5">
    <label class="form-label" for="customer_visible_summary">Customer-visible summary</label>
    <textarea class="form-textarea" id="customer_visible_summary" name="customer_visible_summary" maxlength="5000">{{ old('customer_visible_summary', $ticket->customer_visible_summary ?? '') }}</textarea>
</div>
