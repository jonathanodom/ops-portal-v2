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
<div class="mt-5">
    <label class="form-label" for="description">Work scope</label>
    <textarea class="form-textarea" id="description" name="description" maxlength="10000">{{ old('description', $ticket->description ?? '') }}</textarea>
</div>
<div class="mt-5">
    <label class="form-label" for="customer_visible_summary">Customer-visible summary</label>
    <textarea class="form-textarea" id="customer_visible_summary" name="customer_visible_summary" maxlength="5000">{{ old('customer_visible_summary', $ticket->customer_visible_summary ?? '') }}</textarea>
</div>
