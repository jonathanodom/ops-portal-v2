@props([
    'id',
    'action',
    'services' => collect(),
    'products' => collect(),
    'packages' => collect(),
    'showPrices' => false,
    'fieldMode' => false,
])

@php($hasItems = $services->isNotEmpty() || $products->isNotEmpty() || $packages->isNotEmpty())
<div data-catalog-picker>
    <button type="button" class="button-primary" data-catalog-dialog-open="{{ $id }}" @disabled(!$hasItems)>Add Catalog item</button>
    @unless($hasItems)<p class="mt-2 text-sm text-slate-500">No active Catalog items are available.</p>@endunless

    <dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" class="m-auto h-[100dvh] max-h-none w-screen max-w-none bg-white p-0 text-slate-950 backdrop:bg-slate-950/60 sm:h-[92vh] sm:w-[96vw] sm:max-w-[1200px] sm:rounded-xl" data-catalog-dialog>
        <form method="POST" action="{{ $action }}" class="flex h-full min-h-0 flex-col" data-catalog-form>
            @csrf
            <header class="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-brand-blue">Products &amp; Services</p>
                    <h2 id="{{ $id }}-title" class="text-xl font-bold">Add Catalog item</h2>
                </div>
                <button type="button" class="button-secondary min-h-11 min-w-11" data-catalog-dialog-close aria-label="Close Catalog picker">Close</button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                <p class="text-sm text-slate-600">Choose a Service, Product, or Package. Catalog identity, pricing defaults, tax behavior, and Package recipe are snapshotted when you add it.</p>

                <div class="mt-5">
                    <label class="form-label" for="{{ $id }}-search">Search Catalog</label>
                    <input class="form-input" id="{{ $id }}-search" type="search" autocomplete="off" placeholder="Search name or code" data-catalog-search>
                    <p class="mt-1 text-sm text-slate-600" role="status" aria-live="polite" data-catalog-status></p>
                </div>

                <div class="mt-4">
                    <label class="form-label" for="{{ $id }}-item">Catalog item</label>
                    <select class="form-input" id="{{ $id }}-item" name="catalog_item" required data-catalog-item>
                        <option value="">Choose an item</option>
                        @if($services->isNotEmpty())
                            <optgroup label="Services">
                                @foreach($services as $service)
                                    <option value="service:{{ $service->id }}" data-search="{{ strtolower($service->service_code.' '.$service->name.' '.$service->customer_description) }}" data-service-id="{{ $service->id }}">
                                        {{ $service->name }} ({{ $service->service_code }})@if($showPrices) · {{ $service->default_price_cents === null ? 'Price required' : '$'.number_format($service->default_price_cents / 100, 2) }}@endif
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if($products->isNotEmpty())
                            <optgroup label="Products">
                                @foreach($products as $product)
                                    <option value="product:{{ $product->id }}" data-search="{{ strtolower($product->product_code.' '.$product->sku.' '.$product->name.' '.$product->manufacturer.' '.$product->model.' '.$product->customer_description) }}">
                                        {{ $product->name }} ({{ $product->product_code }})@if($showPrices) · {{ $product->default_sell_price_cents === null ? 'Price required' : '$'.number_format($product->default_sell_price_cents / 100, 2) }}@endif
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if($packages->isNotEmpty())
                            <optgroup label="Packages">
                                @foreach($packages as $package)
                                    <option value="package:{{ $package->id }}" data-search="{{ strtolower($package->package_code.' '.$package->name.' '.$package->customer_description) }}">
                                        {{ $package->name }} ({{ $package->package_code }})@if($showPrices) · {{ $package->default_price_cents === null ? 'Price required' : '$'.number_format($package->default_price_cents / 100, 2) }}@endif
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>

                <div class="mt-4" data-catalog-variant-wrap hidden>
                    <label class="form-label" for="{{ $id }}-variant">Service Variant</label>
                    <select class="form-input" id="{{ $id }}-variant" name="catalog_service_variant_id" data-catalog-variant>
                        <option value="">Choose a Variant</option>
                        @foreach($services as $service)
                            @foreach($service->variants as $variant)
                                <option value="{{ $variant->id }}" data-service-id="{{ $service->id }}">{{ $service->name }} — {{ $variant->customer_label ?: $variant->label }}@if($showPrices) · {{ $variant->price_override_cents === null ? 'Service default' : '$'.number_format($variant->price_override_cents / 100, 2) }}@endif</option>
                            @endforeach
                        @endforeach
                    </select>
                </div>

                <div class="mt-4">
                    <label class="form-label" for="{{ $id }}-quantity">Quantity</label>
                    <input class="form-input" id="{{ $id }}-quantity" name="catalog_quantity" value="1" inputmode="decimal" required>
                </div>

                @if($fieldMode)
                    <div class="mt-4">
                        <label class="form-label" for="{{ $id }}-treatment">Billing treatment</label>
                        <select class="form-input" id="{{ $id }}-treatment" name="billing_treatment">
                            @foreach(config('field_execution.billing_treatments') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="mt-4">
                        <label class="form-label" for="{{ $id }}-note">Technician note</label>
                        <textarea class="form-textarea" id="{{ $id }}-note" name="technician_note" rows="3"></textarea>
                    </div>
                    <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-slate-700">Field selection does not permit price entry or price override. Billing reviews the snapshotted Catalog default before invoice issue.</div>
                @endif
            </div>

            <footer class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-4 py-3 pb-[max(.75rem,env(safe-area-inset-bottom))] sm:px-6">
                <button type="button" class="button-secondary" data-catalog-dialog-close>Cancel</button>
                <button class="button-primary">Add item</button>
            </footer>
        </form>
    </dialog>
</div>
