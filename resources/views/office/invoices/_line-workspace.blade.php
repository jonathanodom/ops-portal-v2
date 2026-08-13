<section id="invoice-lines" class="surface scroll-mt-32 overflow-hidden" aria-labelledby="invoice-lines-heading" data-invoice-item-workspace>
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.08em] text-brand-blue">Invoice lines</p>
            <h2 id="invoice-lines-heading" class="mt-1 text-xl font-bold text-slate-950">Products and services</h2>
            <p class="mt-1 text-sm text-slate-600">Review quantities, rates, tax treatment, and charge totals before issue.</p>
        </div>
        @if($invoice->isEditable())
            <div class="flex flex-wrap gap-2" aria-label="Add invoice item">
                @if($canUseCatalog)<x-catalog-picker id="invoice-catalog-picker" :action="route('office.invoices.catalog-lines.store', $invoice)" :services="$catalogServices" :products="$catalogProducts" :packages="$catalogPackages" :show-prices="true" button-label="+ Add Catalog Item" />@endif
                <button type="button" class="button-secondary" data-invoice-item-open="invoice-manual-line-dialog">+ Add Manual Line</button>
            </div>
        @endif
    </header>

    @forelse($invoice->lines as $line)
        @php
            $quantity = rtrim(rtrim(number_format($line->quantity_millis / 1000, 3, '.', ''), '0'), '.');
            $treatment = $line->billing_treatment ? ucfirst(str_replace('_', ' ', $line->billing_treatment)) : null;
        @endphp
        @if($loop->first)
            <div class="hidden overflow-x-auto lg:block" data-invoice-item-table>
                <table class="w-full border-collapse text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-[0.06em] text-slate-600"><tr><th class="px-5 py-3 font-bold">Item / description</th><th class="px-3 py-3 text-right font-bold">Qty</th><th class="px-3 py-3 font-bold">Unit</th><th class="px-3 py-3 text-right font-bold">Rate</th><th class="px-3 py-3 text-center font-bold">Tax</th><th class="px-3 py-3 text-right font-bold">Amount</th><th class="px-5 py-3 text-right font-bold"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
        @endif
                        <tr @class(['bg-slate-50/70 text-slate-600' => ! $line->included])>
                            <td class="max-w-xl px-5 py-4 align-top">
                                <div class="flex flex-wrap items-center gap-2"><span class="font-bold text-slate-950">{{ $line->description }}</span>@unless($line->included)<span class="status-inactive">Excluded</span>@endunless</div>
                                <p class="mt-1 text-xs text-slate-600">{{ ucfirst(str_replace('_', ' ', $line->line_type)) }}@if($treatment) &middot; {{ $treatment }}@endif @if($line->laborRate) &middot; {{ $line->laborRate->name }} rate @endif</p>
                                @if($line->catalog_item_type)
                                    <details class="mt-2 max-w-lg text-xs text-slate-600" data-catalog-source-details><summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Catalog source details</summary><div class="rounded-lg border border-blue-200 bg-blue-50 p-3"><p><strong>{{ ucfirst($line->catalog_item_type) }}:</strong> {{ $line->catalog_name_snapshot }} &middot; {{ $line->catalog_code_snapshot }}</p><p class="mt-1">Snapshotted default: {{ $line->catalog_original_unit_price_cents === null ? 'Price required' : '$'.number_format($line->catalog_original_unit_price_cents / 100, 2) }}</p>@if($line->catalog_package_recipe_snapshot)<p class="mt-1"><strong>Internal package recipe:</strong> {{ count($line->catalog_package_recipe_snapshot['recipe'] ?? []) }} snapshotted components. Recipe demand is never rendered on customer invoice documents.</p>@endif</div></details>
                                @elseif($line->sourceVisit)
                                    <p class="mt-2 text-xs text-slate-500">Billing source: {{ $line->sourceVisit->displayLabel() }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-4 text-right align-top font-semibold">{{ $quantity }}</td>
                            <td class="whitespace-nowrap px-3 py-4 align-top">{{ $line->unit ?: '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-right align-top">{{ $line->unit_price_cents === null ? 'Needs price' : '$'.number_format($line->unit_price_cents / 100, 2) }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-center align-top">{{ $line->taxable ? 'Yes' : 'No' }}</td>
                            <td class="whitespace-nowrap px-3 py-4 text-right align-top font-bold text-slate-950">${{ number_format($line->total_cents / 100, 2) }}</td>
                            <td class="px-5 py-4 text-right align-top">@if($invoice->isEditable())<button type="button" class="button-secondary" data-invoice-item-open="invoice-line-editor-{{ $line->id }}" aria-label="Edit {{ $line->description }}">Edit</button>@endif</td>
                        </tr>
        @if($loop->last)
                    </tbody>
                </table>
            </div>
        @endif
    @empty
        <div class="px-5 py-10 text-center"><p class="font-bold text-slate-900">No invoice items</p><p class="mt-1 text-sm text-slate-600">Add a Catalog item or manual line to prepare this invoice.</p></div>
    @endforelse

    @if($invoice->lines->isNotEmpty())
        <div class="divide-y divide-slate-200 lg:hidden" data-invoice-item-cards>
            @foreach($invoice->lines as $line)
                @php($quantity = rtrim(rtrim(number_format($line->quantity_millis / 1000, 3, '.', ''), '0'), '.'))
                <article class="p-4" @if(! $line->included) data-line-excluded @endif>
                    <div class="flex items-start justify-between gap-3"><div><h3 class="font-bold text-slate-950">{{ $line->description }}</h3><p class="mt-1 text-sm text-slate-600">{{ ucfirst(str_replace('_', ' ', $line->line_type)) }}@unless($line->included) &middot; Excluded @endunless</p></div><p class="whitespace-nowrap text-lg font-bold">${{ number_format($line->total_cents / 100, 2) }}</p></div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="font-semibold text-slate-500">Quantity</dt><dd class="mt-1">{{ $quantity }} {{ $line->unit }}</dd></div><div><dt class="font-semibold text-slate-500">Rate</dt><dd class="mt-1">{{ $line->unit_price_cents === null ? 'Needs price' : '$'.number_format($line->unit_price_cents / 100, 2) }}</dd></div><div><dt class="font-semibold text-slate-500">Taxable</dt><dd class="mt-1">{{ $line->taxable ? 'Yes' : 'No' }}</dd></div><div><dt class="font-semibold text-slate-500">Treatment</dt><dd class="mt-1">{{ $line->billing_treatment ? ucfirst(str_replace('_', ' ', $line->billing_treatment)) : 'Not applicable' }}</dd></div></dl>
                    @if($line->catalog_item_type)<details class="mt-2 text-sm text-slate-600" data-catalog-source-details><summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Catalog source details</summary><div class="rounded-lg border border-blue-200 bg-blue-50 p-3"><p>{{ $line->catalog_name_snapshot }} &middot; {{ $line->catalog_code_snapshot }}</p>@if($line->catalog_package_recipe_snapshot)<p class="mt-1 text-xs">Internal package recipe: {{ count($line->catalog_package_recipe_snapshot['recipe'] ?? []) }} snapshotted components.</p>@endif</div></details>@endif
                    @if($invoice->isEditable())<button type="button" class="button-secondary mt-3 w-full" data-invoice-item-open="invoice-line-editor-{{ $line->id }}">Edit item</button>@endif
                </article>
            @endforeach
        </div>
    @endif
</section>

@if($invoice->isEditable())
    @foreach($invoice->lines as $line)@include('office.invoices._line-editor', ['line' => $line])@endforeach
    @include('office.invoices._manual-line-dialog')
@endif
