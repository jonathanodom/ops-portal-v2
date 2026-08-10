<x-layouts.office title="Billing settings">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <a class="text-sm font-bold text-brand-blue" href="{{ route('office.billing-handoffs.index') }}">← Billing</a>
    <h1 class="mt-2 text-3xl font-bold">Billing settings</h1>
    <p class="mt-2 text-slate-600">Seller identity and one active default labor rate are required before invoices can move through billing.</p>
    <form method="POST" action="{{ route('office.billing.settings.update') }}" class="surface mt-6 p-5">@csrf @method('PUT')
        <h2 class="text-xl font-bold">Seller and defaults</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            @foreach(['seller_name'=>'Display name','seller_legal_name'=>'Legal name','seller_email'=>'Billing email','seller_phone'=>'Phone','seller_address_line_1'=>'Address line 1','seller_address_line_2'=>'Address line 2','seller_city'=>'City','seller_state'=>'State','seller_postal_code'=>'ZIP'] as $field=>$label)
                <div><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-input @error($field) border-danger @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$settings->$field) }}" @if(!in_array($field,['seller_legal_name','seller_address_line_2'])) required @endif>@error($field)<p class="form-error">{{ $message }}</p>@enderror</div>
            @endforeach
            <div><label class="form-label" for="default_payment_terms">Default terms</label><select class="form-input" id="default_payment_terms" name="default_payment_terms"><option value="due_on_receipt" @selected(old('default_payment_terms',$settings->default_payment_terms)==='due_on_receipt')>Due on receipt</option><option value="custom" @selected(old('default_payment_terms',$settings->default_payment_terms)==='custom')>Custom due date</option></select></div>
            <div><label class="form-label" for="default_tax_rate_percent">Default tax rate (%)</label><input class="form-input" id="default_tax_rate_percent" name="default_tax_rate_percent" inputmode="decimal" value="{{ old('default_tax_rate_percent',number_format($settings->default_tax_rate_basis_points/100,2,'.','')) }}" required></div>
        </div>
        <button class="button-primary mt-5">Save billing settings</button>
    </form>
    <section class="surface mt-6 p-5"><h2 class="text-xl font-bold">Named labor rates</h2>
        <div class="mt-4 space-y-3">@foreach($rates as $rate)<form method="POST" action="{{ route('office.billing.settings.rates.update',$rate) }}" class="grid items-end gap-3 border-b border-slate-200 pb-3 md:grid-cols-[1fr_180px_auto_auto_auto]">@csrf @method('PUT')<p class="min-h-11 py-3 font-bold">{{ $rate->name }}</p><div><label class="form-label" for="rate-{{ $rate->id }}">Hourly rate</label><input class="form-input" id="rate-{{ $rate->id }}" name="hourly_rate" value="{{ number_format($rate->hourly_rate_cents/100,2,'.','') }}" required></div><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="active" value="1" @checked($rate->active)> Active</label><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_default" value="1" @checked($rate->is_default)> Default</label><button class="button-secondary">Update</button></form>@endforeach</div>
        <form method="POST" action="{{ route('office.billing.settings.rates.store') }}" class="mt-6 grid items-end gap-3 md:grid-cols-[1fr_180px_auto_auto]">@csrf<div><label class="form-label" for="rate-name">Rate name</label><input class="form-input" id="rate-name" name="name" placeholder="Standard" required></div><div><label class="form-label" for="hourly-rate">Hourly rate</label><input class="form-input" id="hourly-rate" name="hourly_rate" inputmode="decimal" placeholder="125.00" required></div><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="is_default" value="1"> Default</label><button class="button-primary">Add rate</button></form>
    </section>
</x-layouts.office>
