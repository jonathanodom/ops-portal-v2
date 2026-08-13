@php
    $invoiceDate = $invoice->issued_at ?: $invoice->created_at;
    $termsLabel = $invoice->payment_terms === 'due_on_receipt' ? 'Due on receipt' : 'Custom due date';
@endphp
<section id="invoice-preview" class="invoice-billing-summary scroll-mt-32" aria-labelledby="billing-summary-title">
    <div>
        <p class="text-xs font-bold uppercase tracking-[0.08em] text-slate-500">Bill to</p>
        <h2 id="billing-summary-title" class="mt-2 text-lg font-bold text-slate-950">{{ $invoice->billing_name }}</h2>
        @if($invoice->billing_legal_name)<p class="mt-1 text-sm text-slate-600">{{ $invoice->billing_legal_name }}</p>@endif
        <address class="mt-3 text-sm not-italic leading-6 text-slate-700">
            @if($invoice->billing_address_line_1)<span class="block">{{ $invoice->billing_address_line_1 }}</span>@endif
            @if($invoice->billing_address_line_2)<span class="block">{{ $invoice->billing_address_line_2 }}</span>@endif
            @if($invoice->billing_city || $invoice->billing_state || $invoice->billing_postal_code)<span class="block">{{ collect([$invoice->billing_city, $invoice->billing_state])->filter()->join(', ') }} {{ $invoice->billing_postal_code }}</span>@endif
            @if($invoice->billing_email)<a class="block font-semibold text-brand-blue" href="mailto:{{ $invoice->billing_email }}">{{ $invoice->billing_email }}</a>@endif
            @if($invoice->billing_phone)<a class="block font-semibold text-brand-blue" href="tel:{{ preg_replace('/[^0-9+]/', '', $invoice->billing_phone) }}">{{ $invoice->billing_phone }}</a>@endif
        </address>
    </div>
    <dl class="invoice-billing-meta">
        <div><dt>Invoice date</dt><dd><x-local-time :value="$invoiceDate" :timezone="$activeOrganization->timezone" format="M j, Y" /></dd></div>
        <div><dt>Terms</dt><dd>{{ $termsLabel }}</dd></div>
        <div><dt>Due date</dt><dd>{{ $invoice->due_on?->format('M j, Y') ?? ($invoice->payment_terms === 'due_on_receipt' ? 'Upon receipt' : 'Not set') }}</dd></div>
        <div><dt>Service location</dt><dd>{{ $invoice->serviceLocation?->name ?? 'Not specified' }}</dd></div>
    </dl>
    @if($invoice->isEditable())
        <div class="sm:col-span-2 xl:col-span-1 xl:text-right"><button type="button" class="button-secondary" data-invoice-billing-open>Edit billing details</button></div>
    @endif
</section>

@if($invoice->isEditable())
<dialog id="invoice-billing-dialog" class="invoice-billing-dialog" data-invoice-billing-dialog data-auto-open="{{ old('form_context') === 'billing' && $errors->any() ? 'true' : 'false' }}" aria-labelledby="invoice-billing-dialog-title">
    <form method="POST" action="{{ route('office.invoices.update', $invoice) }}" class="invoice-billing-dialog-panel" data-invoice-billing-form>
        @csrf @method('PUT')
        <input type="hidden" name="form_context" value="billing">
        <header class="invoice-billing-dialog-header">
            <div><p class="text-sm font-bold text-brand-blue">{{ $invoice->invoice_number }}</p><h2 id="invoice-billing-dialog-title" class="mt-1 text-xl font-bold text-slate-950">Edit billing details</h2></div>
            <button type="button" class="button-secondary min-w-11 px-3" data-invoice-billing-close aria-label="Close billing details">Close</button>
        </header>
        <div class="invoice-billing-dialog-body">
            @if(old('form_context') === 'billing' && $errors->any())
                <div class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">Billing details need attention</p><p class="mt-1 text-sm">Review the marked fields and try again.</p></div>
            @endif
            <section aria-labelledby="billing-customer-heading"><h3 id="billing-customer-heading" class="text-base font-bold text-slate-950">Customer and contact</h3><div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach(['billing_name'=>'Customer name','billing_legal_name'=>'Legal name','billing_contact_name'=>'Point of contact','billing_email'=>'Email','billing_phone'=>'Phone'] as $field=>$label)
                    <div @class(['sm:col-span-2' => in_array($field, ['billing_name', 'billing_legal_name'], true)])><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $invoice->$field) }}" @if($field === 'billing_name') required @endif @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>@error($field)<p id="{{ $field }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                @endforeach
            </div></section>
            <section class="mt-7 border-t border-slate-200 pt-6" aria-labelledby="billing-address-heading"><h3 id="billing-address-heading" class="text-base font-bold text-slate-950">Billing address</h3><div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach(['billing_address_line_1'=>'Address line 1','billing_address_line_2'=>'Address line 2','billing_city'=>'City','billing_state'=>'State','billing_postal_code'=>'ZIP'] as $field=>$label)
                    <div @class(['sm:col-span-2' => str_contains($field, 'line_')])><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-input" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $invoice->$field) }}" @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>@error($field)<p id="{{ $field }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                @endforeach
            </div></section>
            <section class="mt-7 border-t border-slate-200 pt-6" aria-labelledby="billing-terms-heading"><div class="flex flex-wrap items-end justify-between gap-2"><h3 id="billing-terms-heading" class="text-base font-bold text-slate-950">Terms, tax, and discount</h3><p class="text-sm text-slate-600">Invoice date: <x-local-time :value="$invoiceDate" :timezone="$activeOrganization->timezone" format="M j, Y" /></p></div><div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div><label class="form-label" for="payment_terms">Payment terms</label><select class="form-input" id="payment_terms" name="payment_terms"><option value="due_on_receipt" @selected(old('payment_terms', $invoice->payment_terms) === 'due_on_receipt')>Due on receipt</option><option value="custom" @selected(old('payment_terms', $invoice->payment_terms) === 'custom')>Custom date</option></select>@error('payment_terms')<p id="payment_terms-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                <div><label class="form-label" for="due_on">Due date</label><input class="form-input" id="due_on" name="due_on" type="date" value="{{ old('due_on', $invoice->due_on?->format('Y-m-d')) }}" @error('due_on') aria-invalid="true" aria-describedby="due_on-error" @enderror>@error('due_on')<p id="due_on-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                <div><label class="form-label" for="tax_rate_percent">Tax rate (%)</label><input class="form-input" id="tax_rate_percent" name="tax_rate_percent" value="{{ old('tax_rate_percent', number_format($invoice->tax_rate_basis_points / 100, 2, '.', '')) }}" required @error('tax_rate_percent') aria-invalid="true" aria-describedby="tax_rate_percent-error" @enderror>@error('tax_rate_percent')<p id="tax_rate_percent-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                <div><label class="form-label" for="tax_override_reason">Tax override reason</label><textarea class="form-textarea" id="tax_override_reason" name="tax_override_reason" @error('tax_override_reason') aria-invalid="true" aria-describedby="tax_override_reason-error" @enderror>{{ old('tax_override_reason', $invoice->tax_override_reason) }}</textarea>@error('tax_override_reason')<p id="tax_override_reason-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                @if($activeMembership->hasCapability('invoices.discount'))
                    <div><label class="form-label" for="discount_type">Invoice discount</label><select class="form-input" id="discount_type" name="discount_type"><option value="">None</option><option value="fixed" @selected(old('discount_type', $invoice->discount_type) === 'fixed')>Fixed amount</option><option value="percent" @selected(old('discount_type', $invoice->discount_type) === 'percent')>Percent</option></select></div>
                    <div><label class="form-label" for="discount_value_input">Discount value</label><input class="form-input" id="discount_value_input" name="discount_value_input" value="{{ old('discount_value_input', number_format($invoice->discount_value / 100, 2, '.', '')) }}" @error('discount_value_input') aria-invalid="true" aria-describedby="discount_value_input-error" @enderror>@error('discount_value_input')<p id="discount_value_input-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror</div>
                    <div class="sm:col-span-2"><label class="form-label" for="discount_reason">Discount reason</label><textarea class="form-textarea" id="discount_reason" name="discount_reason">{{ old('discount_reason', $invoice->discount_reason) }}</textarea></div>
                @endif
            </div></section>
            <section class="mt-7 border-t border-slate-200 pt-6" aria-labelledby="billing-notes-heading"><h3 id="billing-notes-heading" class="text-base font-bold text-slate-950">Notes</h3><div class="mt-4 grid gap-4 lg:grid-cols-2"><div><label class="form-label" for="customer_note">Customer note</label><textarea class="form-textarea" id="customer_note" name="customer_note">{{ old('customer_note', $invoice->customer_note) }}</textarea></div><div><label class="form-label" for="internal_note">Internal billing note</label><textarea class="form-textarea" id="internal_note" name="internal_note">{{ old('internal_note', $invoice->internal_note) }}</textarea></div></div></section>
        </div>
        <footer class="invoice-billing-dialog-footer"><button type="button" class="button-secondary" data-invoice-billing-close>Cancel</button><button class="button-primary">Save billing details</button></footer>
    </form>
</dialog>
@endif
