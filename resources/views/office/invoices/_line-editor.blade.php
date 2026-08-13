@php
    $formContext = (string) old('line_form_context');
    $isCurrentEditor = $formContext === (string) $line->id;
    $value = fn (string $field, mixed $fallback) => $isCurrentEditor ? old($field, $fallback) : $fallback;
    $quantity = rtrim(rtrim(number_format($line->quantity_millis / 1000, 3, '.', ''), '0'), '.');
@endphp
<dialog
    id="invoice-line-editor-{{ $line->id }}"
    class="invoice-line-dialog"
    data-invoice-item-dialog
    data-auto-open="{{ $isCurrentEditor && $errors->any() ? 'true' : 'false' }}"
    aria-labelledby="invoice-line-editor-title-{{ $line->id }}"
>
    <form method="POST" action="{{ route('office.invoices.lines.update', [$invoice, $line]) }}" class="invoice-line-dialog-panel" data-invoice-item-form>
        @csrf @method('PUT')
        <input type="hidden" name="line_form_context" value="{{ $line->id }}">
        <header class="invoice-line-dialog-header">
            <div class="min-w-0">
                <p class="text-sm font-bold text-brand-blue">{{ $invoice->invoice_number }}</p>
                <h2 id="invoice-line-editor-title-{{ $line->id }}" class="mt-1 truncate text-xl font-bold text-slate-950">Edit invoice item</h2>
                <p class="mt-1 truncate text-sm text-slate-600">{{ $line->description }}</p>
            </div>
            <button type="button" class="button-secondary min-w-11 px-3" data-invoice-item-close aria-label="Close line editor">Close</button>
        </header>
        <div class="invoice-line-dialog-body">
            @if($isCurrentEditor && $errors->any())
                <div class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 text-red-900" role="alert">
                    <p class="font-bold">This invoice item needs attention</p>
                    <p class="mt-1 text-sm">Review the marked fields and try again.</p>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label" for="type-{{ $line->id }}">Type</label>
                    <select class="form-input" id="type-{{ $line->id }}" name="line_type" @if($isCurrentEditor) @error('line_type') aria-invalid="true" aria-describedby="line-type-{{ $line->id }}-error" @enderror @endif>
                        @foreach(['labor', 'travel', 'service_charge', 'part', 'equipment', 'other'] as $type)<option value="{{ $type }}" @selected($value('line_type', $line->line_type) === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>@endforeach
                    </select>
                    @if($isCurrentEditor) @error('line_type')<p id="line-type-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
                <div class="sm:col-span-2">
                    <label class="form-label" for="description-{{ $line->id }}">Description</label>
                    <input class="form-input" id="description-{{ $line->id }}" name="description" value="{{ $value('description', $line->description) }}" required @if($isCurrentEditor) @error('description') aria-invalid="true" aria-describedby="line-description-{{ $line->id }}-error" @enderror @endif>
                    @if($isCurrentEditor) @error('description')<p id="line-description-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
                <div>
                    <label class="form-label" for="quantity-{{ $line->id }}">Quantity</label>
                    <input class="form-input" id="quantity-{{ $line->id }}" name="quantity" value="{{ $value('quantity', $quantity) }}" inputmode="decimal" required @if($isCurrentEditor) @error('quantity') aria-invalid="true" aria-describedby="line-quantity-{{ $line->id }}-error" @enderror @endif>
                    @if($isCurrentEditor) @error('quantity')<p id="line-quantity-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
                <div>
                    <label class="form-label" for="unit-{{ $line->id }}">Unit</label>
                    <input class="form-input" id="unit-{{ $line->id }}" name="unit" value="{{ $value('unit', $line->unit) }}" @if($isCurrentEditor) @error('unit') aria-invalid="true" aria-describedby="line-unit-{{ $line->id }}-error" @enderror @endif>
                    @if($isCurrentEditor) @error('unit')<p id="line-unit-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
                <div>
                    <label class="form-label" for="price-{{ $line->id }}">Unit price</label>
                    <input class="form-input" id="price-{{ $line->id }}" name="unit_price" value="{{ $value('unit_price', $line->unit_price_cents === null ? '' : number_format($line->unit_price_cents / 100, 2, '.', '')) }}" inputmode="decimal" required @if($isCurrentEditor) @error('unit_price') aria-invalid="true" aria-describedby="line-price-{{ $line->id }}-error" @enderror @endif>
                    @if($isCurrentEditor) @error('unit_price')<p id="line-price-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
                <div>
                    <label class="form-label" for="treatment-{{ $line->id }}">Billing treatment</label>
                    <select class="form-input" id="treatment-{{ $line->id }}" name="billing_treatment">
                        <option value="">Not applicable</option>
                        @foreach(['billable', 'warranty', 'customer_owned', 'no_charge'] as $treatment)<option value="{{ $treatment }}" @selected($value('billing_treatment', $line->billing_treatment) === $treatment)>{{ ucfirst(str_replace('_', ' ', $treatment)) }}</option>@endforeach
                    </select>
                </div>
                @if($line->line_type === 'labor' && !$line->catalog_item_type)
                    <div>
                        <label class="form-label" for="rate-{{ $line->id }}">Named labor rate</label>
                        <select class="form-input" id="rate-{{ $line->id }}" name="labor_rate_id">
                            <option value="">Manual</option>
                            @foreach($rates as $rate)<option value="{{ $rate->id }}" @selected((string) $value('labor_rate_id', $line->labor_rate_id) === (string) $rate->id)>{{ $rate->name }} &middot; ${{ number_format($rate->hourly_rate_cents / 100, 2) }}/hr</option>@endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="labor_rate_id" value="">
                @endif
                <div class="flex min-h-11 items-center gap-5">
                    <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="included" value="1" @checked((bool) $value('included', $line->included))> Included</label>
                    <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="taxable" value="1" @checked((bool) $value('taxable', $line->taxable))> Taxable</label>
                </div>
                <div class="sm:col-span-2">
                    <label class="form-label" for="reason-{{ $line->id }}">Reason for invoice adjustment</label>
                    <textarea class="form-textarea" id="reason-{{ $line->id }}" name="override_reason" required @if($isCurrentEditor) @error('override_reason') aria-invalid="true" aria-describedby="line-reason-{{ $line->id }}-error" @enderror @endif>{{ $value('override_reason', '') }}</textarea>
                    @if($isCurrentEditor) @error('override_reason')<p id="line-reason-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                </div>
            </div>
        </div>
        <footer class="invoice-line-dialog-footer">
            <p class="mr-auto text-sm font-semibold text-slate-700">Current total: ${{ number_format($line->total_cents / 100, 2) }}</p>
            <button type="button" class="button-secondary" data-invoice-item-close>Cancel</button>
            <button class="button-primary">Save invoice item</button>
        </footer>
    </form>
</dialog>
