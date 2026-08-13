@php($isManualEditor = old('line_form_context') === 'manual')
<dialog id="invoice-manual-line-dialog" class="invoice-line-dialog" data-invoice-item-dialog data-auto-open="{{ $isManualEditor && $errors->any() ? 'true' : 'false' }}" aria-labelledby="invoice-manual-line-title">
    <form method="POST" action="{{ route('office.invoices.lines.store', $invoice) }}" class="invoice-line-dialog-panel" data-invoice-item-form>
        @csrf
        <input type="hidden" name="line_form_context" value="manual">
        <header class="invoice-line-dialog-header">
            <div><p class="text-sm font-bold text-brand-blue">{{ $invoice->invoice_number }}</p><h2 id="invoice-manual-line-title" class="mt-1 text-xl font-bold text-slate-950">Add manual line</h2></div>
            <button type="button" class="button-secondary min-w-11 px-3" data-invoice-item-close aria-label="Close manual line editor">Close</button>
        </header>
        <div class="invoice-line-dialog-body">
            @if($isManualEditor && $errors->any())<div class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900" role="alert"><p class="font-bold">This manual line needs attention</p><p class="mt-1 text-sm">Review the marked fields and try again.</p></div>@endif
            <p class="mb-5 text-sm text-slate-600">Use a manual line for one-off work that does not belong in the Catalog.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="form-label" for="manual-line-type">Type</label><select class="form-input" id="manual-line-type" name="line_type">@foreach(['service_charge', 'travel', 'equipment', 'other'] as $type)<option value="{{ $type }}" @selected(old('line_type', 'service_charge') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><label class="form-label" for="manual-description">Description</label><input class="form-input" id="manual-description" name="description" value="{{ $isManualEditor ? old('description') : '' }}" required @if($isManualEditor) @error('description') aria-invalid="true" aria-describedby="manual-description-error" @enderror @endif>@if($isManualEditor) @error('description')<p id="manual-description-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif</div>
                <div><label class="form-label" for="manual-quantity">Quantity</label><input class="form-input" id="manual-quantity" name="quantity" value="{{ $isManualEditor ? old('quantity', '1') : '1' }}" inputmode="decimal" required @if($isManualEditor) @error('quantity') aria-invalid="true" aria-describedby="manual-quantity-error" @enderror @endif>@if($isManualEditor) @error('quantity')<p id="manual-quantity-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif</div>
                <div><label class="form-label" for="manual-unit">Unit</label><input class="form-input" id="manual-unit" name="unit" value="{{ $isManualEditor ? old('unit') : '' }}"></div>
                <div><label class="form-label" for="manual-price">Unit price</label><input class="form-input" id="manual-price" name="unit_price" value="{{ $isManualEditor ? old('unit_price') : '' }}" inputmode="decimal" required @if($isManualEditor) @error('unit_price') aria-invalid="true" aria-describedby="manual-price-error" @enderror @endif>@if($isManualEditor) @error('unit_price')<p id="manual-price-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif</div>
                <div class="flex min-h-11 items-center"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="taxable" value="1" @checked($isManualEditor && old('taxable'))> Taxable</label></div>
                <input type="hidden" name="included" value="1">
                <div class="sm:col-span-2"><label class="form-label" for="manual-reason">Reason for manual charge</label><textarea class="form-textarea" id="manual-reason" name="override_reason" required @if($isManualEditor) @error('override_reason') aria-invalid="true" aria-describedby="manual-reason-error" @enderror @endif>{{ $isManualEditor ? old('override_reason') : '' }}</textarea>@if($isManualEditor) @error('override_reason')<p id="manual-reason-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif</div>
            </div>
        </div>
        <footer class="invoice-line-dialog-footer"><button type="button" class="button-secondary" data-invoice-item-close>Cancel</button><button class="button-primary">Add manual line</button></footer>
    </form>
</dialog>
