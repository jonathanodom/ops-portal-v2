<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Print {{ $invoice->invoice_number }} | NewDay Tech</title>
    @vite(['resources/css/app.css'])
    <style>
        [hidden] { display: none !important; }
        .print-document { overflow-wrap: anywhere; }
        .print-break-before { break-before: page; page-break-before: always; }
        .print-avoid-break { break-inside: avoid; page-break-inside: avoid; }
        .print-static-service .service-details { margin-top: 0; border-top: 2px solid #1d80f7; padding-top: 1.25rem; }
        .print-static-service .service-block { margin-top: 1.25rem; }
        .print-static-service .service-item { margin-top: .75rem; border: 1px solid #cbd5e1; padding: 1rem; break-inside: avoid; page-break-inside: avoid; }
        .print-static-service .service-list { margin-top: .5rem; padding-left: 1.25rem; list-style: disc; }
        .print-static-service .muted { color: #475569; }
        @page { size: letter portrait; margin: .55in; }
        @media print {
            html, body { background: #fff !important; }
            body { color: #111827; font-size: 10.5pt; }
            .print-controls { display: none !important; }
            .print-document { width: 100%; max-width: none; padding: 0 !important; border: 0 !important; box-shadow: none !important; }
            .print-section { break-inside: auto; page-break-inside: auto; }
            a { color: inherit; text-decoration: none; }
        }
    </style>
</head>
<body class="bg-canvas text-slate-900" data-print-composer>
    @php
        $hasCustomerNote = filled($invoice->customer_note);
        $hasServiceDetails = (bool) $invoice->serviceSnapshot;
        $invoiceAcknowledgment = $invoice->acknowledgments->first();
    @endphp

    <aside class="print-controls sticky top-0 z-10 border-b border-slate-300 bg-white px-4 py-4 shadow-sm" aria-labelledby="print-options-heading">
        <div class="mx-auto max-w-5xl">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-brand-blue">Print options</p>
                    <h1 id="print-options-heading" class="mt-1 text-xl font-bold">Compose {{ $invoice->invoice_number }}</h1>
                    <p class="mt-1 text-sm text-slate-600">Financial details are always included. These choices apply only to this print session.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a class="button-secondary" href="{{ route('invoices.present', $invoice) }}">Back to Invoice</a>
                    <button class="button-secondary" type="button" data-print-reset>Reset defaults</button>
                    <button class="button-primary" type="button" data-print-action>Print</button>
                </div>
            </div>

            @if($hasCustomerNote || $hasServiceDetails || $invoiceAcknowledgment)
                <div class="mt-4 grid gap-4 border-t border-slate-200 pt-4 md:grid-cols-2">
                    <fieldset>
                        <legend class="text-sm font-bold">Include</legend>
                        <div class="mt-2 grid gap-1">
                            @if($hasCustomerNote)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="include-customer-note" type="checkbox" checked data-section-toggle="customer-note"> <span>Customer note</span></label>
                            @endif
                            @if($hasServiceDetails)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="include-service-details" type="checkbox" checked data-section-toggle="service-details"> <span>Service Details</span></label>
                            @endif
                            @if($invoiceAcknowledgment)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="include-invoice-acknowledgment" type="checkbox" data-section-toggle="invoice-acknowledgment"> <span>Invoice acknowledgment</span></label>
                            @endif
                        </div>
                    </fieldset>
                    <fieldset>
                        <legend class="text-sm font-bold">Page breaks</legend>
                        <div class="mt-2 grid gap-1">
                            @if($hasCustomerNote)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="break-customer-note" type="checkbox" data-break-toggle="customer-note"> <span>Start Customer Note on a new page</span></label>
                            @endif
                            @if($hasServiceDetails)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="break-service-details" type="checkbox" checked data-break-toggle="service-details"> <span>Start Service Details on a new page</span></label>
                            @endif
                            @if($invoiceAcknowledgment)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3"><input id="break-invoice-acknowledgment" type="checkbox" disabled data-break-toggle="invoice-acknowledgment"> <span>Start Invoice Acknowledgment on a new page</span></label>
                            @endif
                        </div>
                    </fieldset>
                </div>
            @endif
        </div>
    </aside>

    <main class="print-document mx-auto my-6 max-w-[8.5in] border border-slate-200 bg-white p-6 shadow-sm sm:p-10" aria-label="Printable Invoice">
        <section class="print-section" data-print-section="financial-core">
            <header class="print-avoid-break flex flex-wrap items-start justify-between gap-5 border-b-2 border-brand-blue pb-6">
                <img src="{{ $invoice->seller_logo_asset_id ? route('invoices.brand', $invoice) : asset('images/newday-logo.png') }}" alt="{{ $invoice->seller_name }}" class="max-h-24 w-48 object-contain object-left">
                <div class="text-right">
                    <p class="text-sm font-bold text-brand-blue">INVOICE</p>
                    <h2 class="mt-1 text-2xl font-bold">{{ $invoice->invoice_number }}</h2>
                    <p class="mt-1 text-sm">Issued <x-local-time :value="$invoice->issued_at" :timezone="$activeOrganization->timezone" format="M j, Y" /></p>
                </div>
            </header>

            <div class="print-avoid-break grid gap-6 border-b border-slate-200 py-6 sm:grid-cols-2">
                <section aria-labelledby="print-from-heading"><h3 id="print-from-heading" class="text-xs font-bold uppercase tracking-wider text-slate-500">From</h3><p class="mt-2 font-bold">{{ $invoice->seller_legal_name ?: $invoice->seller_name }}</p><p>{{ $invoice->seller_address_line_1 }}</p>@if($invoice->seller_address_line_2)<p>{{ $invoice->seller_address_line_2 }}</p>@endif<p>{{ $invoice->seller_city }}, {{ $invoice->seller_state }} {{ $invoice->seller_postal_code }}</p><p class="mt-2">{{ $invoice->seller_phone }} · {{ $invoice->seller_email }}</p></section>
                <section aria-labelledby="print-bill-to-heading"><h3 id="print-bill-to-heading" class="text-xs font-bold uppercase tracking-wider text-slate-500">Bill to</h3><p class="mt-2 font-bold">{{ $invoice->billing_legal_name ?: $invoice->billing_name }}</p>@if($invoice->billing_contact_name)<p>{{ $invoice->billing_contact_name }}</p>@endif<p>{{ $invoice->billing_address_line_1 }}</p>@if($invoice->billing_address_line_2)<p>{{ $invoice->billing_address_line_2 }}</p>@endif<p>{{ $invoice->billing_city }}, {{ $invoice->billing_state }} {{ $invoice->billing_postal_code }}</p></section>
            </div>

            <section class="print-avoid-break py-6" aria-labelledby="print-service-identity-heading">
                <h3 id="print-service-identity-heading" class="font-bold">{{ $invoice->serviceTicket ? 'Service' : 'Invoice details' }}</h3>
                <p class="mt-1">@if($invoice->serviceTicket){{ $invoice->serviceSnapshot?->snapshot_json['ticket']['number'] ?? $invoice->serviceTicket->ticket_number }} · {{ $invoice->serviceSnapshot?->snapshot_json['ticket']['title'] ?? $invoice->serviceTicket->title }}@else Direct invoice @endif</p>
                <p class="mt-1 text-sm text-slate-600">{{ $invoice->serviceSnapshot?->snapshot_json['site']['name'] ?? $invoice->serviceLocation->name }}</p>
                @if($invoice->serviceSnapshot?->snapshot_json['requested_service']['summary'] ?? null)<p class="mt-3 text-sm">{{ $invoice->serviceSnapshot->snapshot_json['requested_service']['summary'] }}</p>@endif
            </section>

            <div class="overflow-hidden" role="region" aria-label="Invoice line items">
                <table class="w-full table-fixed border-collapse text-left text-xs sm:text-base">
                    <thead><tr class="border-b-2 border-slate-300 text-xs sm:text-sm"><th class="w-[46%] py-3 pr-2">Description</th><th class="w-[18%] whitespace-nowrap px-1 py-3 text-right sm:px-2">Qty</th><th class="w-[18%] whitespace-nowrap px-1 py-3 text-right sm:px-2">Rate</th><th class="w-[18%] whitespace-nowrap py-3 pl-1 text-right sm:pl-2">Amount</th></tr></thead>
                    <tbody>@foreach($invoice->lines->where('included', true) as $line)<tr class="print-avoid-break border-b border-slate-200"><td class="break-words py-4 pr-2">{{ $line->description }}</td><td class="whitespace-nowrap px-1 py-4 text-right sm:px-2">{{ rtrim(rtrim(number_format($line->quantity_millis / 1000, 3, '.', ''), '0'), '.') }} {{ $line->unit }}</td><td class="whitespace-nowrap px-1 py-4 text-right sm:px-2">${{ number_format($line->unit_price_cents / 100, 2) }}</td><td class="whitespace-nowrap py-4 pl-1 text-right font-semibold sm:pl-2">${{ number_format($line->total_cents / 100, 2) }}</td></tr>@endforeach</tbody>
                </table>
            </div>

            <div class="print-avoid-break ml-auto mt-6 max-w-sm">
                <dl class="space-y-2"><div class="flex justify-between"><dt>Subtotal</dt><dd>${{ number_format($invoice->subtotal_cents / 100, 2) }}</dd></div>@if($invoice->discount_total_cents)<div class="flex justify-between"><dt>Discount</dt><dd>−${{ number_format($invoice->discount_total_cents / 100, 2) }}</dd></div>@endif<div class="flex justify-between"><dt>Tax</dt><dd>${{ number_format($invoice->tax_total_cents / 100, 2) }}</dd></div><div class="flex justify-between border-t-2 border-slate-300 pt-3 text-2xl font-bold"><dt>Total</dt><dd>${{ number_format($invoice->total_cents / 100, 2) }}</dd></div></dl>
                <p class="mt-3 text-right font-semibold">{{ $invoice->payment_terms === 'due_on_receipt' ? 'Due on receipt' : 'Due '.$invoice->due_on?->format('M j, Y') }}</p>
            </div>
        </section>

        @if($hasCustomerNote)
            <section class="print-section mt-8 rounded-lg border border-slate-300 p-4" data-print-section="customer-note" aria-labelledby="print-customer-note-heading">
                <h2 id="print-customer-note-heading" class="font-bold">Customer Note</h2>
                <p class="mt-2 whitespace-pre-line">{{ $invoice->customer_note }}</p>
            </section>
        @endif

        @if($hasServiceDetails)
            <section class="print-section print-break-before print-static-service mt-8" data-print-section="service-details">
                @include('invoices._service-details-pdf', ['serviceContext' => $invoice->serviceSnapshot->snapshot_json])
            </section>
        @endif

        @if($invoiceAcknowledgment)
            <section class="print-section mt-8 border-t-2 border-slate-300 pt-5" data-print-section="invoice-acknowledgment" aria-labelledby="print-invoice-acknowledgment-heading" hidden>
                <h2 id="print-invoice-acknowledgment-heading" class="text-xl font-bold">Invoice Acknowledgment</h2>
                <p class="mt-3">Acknowledged by <strong>{{ $invoiceAcknowledgment->contact_name }}</strong></p>
                <p class="mt-1 text-sm text-slate-600"><x-local-time :value="$invoiceAcknowledgment->acknowledged_at" :timezone="$activeOrganization->timezone" /></p>
            </section>
        @endif
    </main>

    <script>
        (() => {
            const includeControls = new Map();
            document.querySelectorAll('[data-section-toggle]').forEach((control) => includeControls.set(control.dataset.sectionToggle, control));

            const sync = () => {
                document.querySelectorAll('[data-print-section]').forEach((section) => {
                    if (section.dataset.printSection === 'financial-core') return;
                    const include = includeControls.get(section.dataset.printSection);
                    if (include) section.hidden = !include.checked;
                });

                document.querySelectorAll('[data-break-toggle]').forEach((control) => {
                    const include = includeControls.get(control.dataset.breakToggle);
                    const section = document.querySelector(`[data-print-section="${control.dataset.breakToggle}"]`);
                    const visible = Boolean(include?.checked);
                    control.disabled = !visible;
                    section?.classList.toggle('print-break-before', visible && control.checked);
                });
            };

            document.querySelectorAll('[data-section-toggle], [data-break-toggle]').forEach((control) => control.addEventListener('change', sync));
            document.querySelector('[data-print-reset]')?.addEventListener('click', () => {
                document.querySelectorAll('[data-section-toggle], [data-break-toggle]').forEach((control) => {
                    control.checked = control.defaultChecked;
                });
                sync();
            });
            document.querySelector('[data-print-action]')?.addEventListener('click', () => window.print());
            sync();
        })();
    </script>
</body>
</html>
