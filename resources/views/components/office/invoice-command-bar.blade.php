@props(['invoice', 'canDeleteDraft' => false])
@php
    $isPaid = $invoice->status === 'issued' && $invoice->paymentState() === 'paid';
    $displayStatus = $isPaid ? 'Paid' : ucfirst(str_replace('_', ' ', $invoice->status));
    $statusClass = $invoice->status === 'void' ? 'status-inactive' : ($isPaid || $invoice->status === 'issued' ? 'status-active' : 'status-hold');
    $hasOpenAttempt = $invoice->relationLoaded('paymentAttempts') && $invoice->paymentAttempts->contains(fn ($attempt) => $attempt->isOpen());
@endphp

<section class="invoice-command-bar" data-invoice-command-bar aria-label="Invoice actions">
    <div class="invoice-command-bar-identity">
        <a class="invoice-command-back" href="{{ route('office.invoices.index') }}" aria-label="Back to Invoices">&larr; Invoices</a>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-lg font-bold text-slate-950 sm:text-xl">{{ $invoice->invoice_number }}</h1>
                <span class="{{ $statusClass }}">{{ $displayStatus }}</span>
            </div>
            <p class="mt-1 truncate text-sm text-slate-600">{{ $invoice->billing_name }} <span aria-hidden="true">&middot;</span> {{ $invoice->serviceTicket?->ticket_number ?? 'Direct invoice' }}</p>
        </div>
    </div>

    @if($invoice->status === 'issued' && $activeMembership->hasCapability('payments.view'))
        <button type="button" class="invoice-command-total group" data-payment-overlay-open="payment-history-dialog" aria-label="Open payment history. Balance due ${{ number_format(max(0, $invoice->balanceCents()) / 100, 2) }}">
            <span class="block text-xs font-bold uppercase tracking-[0.08em] text-slate-500 group-hover:text-brand-blue">Balance due</span>
            <span class="block text-xl font-bold text-slate-950">${{ number_format(max(0, $invoice->balanceCents()) / 100, 2) }}</span>
            <span class="mt-1 block text-xs font-semibold text-brand-blue">View payment history</span>
        </button>
    @else
    <div class="invoice-command-total">
        <p class="text-xs font-bold uppercase tracking-[0.08em] text-slate-500">{{ $invoice->status === 'issued' ? 'Balance due' : 'Invoice total' }}</p>
        <p class="text-xl font-bold text-slate-950">${{ number_format(($invoice->status === 'issued' ? max(0, $invoice->balanceCents()) : $invoice->total_cents) / 100, 2) }}</p>
    </div>
    @endif

    <div class="invoice-command-actions">
        @if($invoice->status === 'draft')
            <a class="button-secondary" href="#invoice-preview">Preview</a>
            <button class="button-secondary" type="button" data-invoice-billing-open>Billing details</button>
            <form method="POST" action="{{ route('office.invoices.ready', $invoice) }}">@csrf<button class="button-primary">Ready for review</button></form>
        @elseif($invoice->status === 'ready_for_review')
            <a class="button-secondary" href="#invoice-preview">Preview</a>
            <a class="button-secondary" href="#invoice-lines">Edit</a>
            @if($activeMembership->hasCapability('invoices.issue'))
                <form method="POST" action="{{ route('office.invoices.issue', $invoice) }}" class="flex flex-wrap items-center gap-2">@csrf
                    <input type="hidden" name="issue_token" value="{{ Str::uuid() }}">
                    <label class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold"><input type="checkbox" name="confirm_issue" value="1" required> Confirm totals</label>
                    <button class="button-primary">Issue invoice</button>
                </form>
            @endif
        @elseif($invoice->status === 'issued')
            <a class="button-secondary" href="{{ route('invoices.present', $invoice) }}">Customer view</a>
            @if($invoice->pdf_status === 'ready')<a class="button-secondary" href="{{ route('invoices.pdf', $invoice) }}">PDF</a>@endif
            @if($activeMembership->hasCapability('payments.view'))
                @if($isPaid)
                    <button class="button-secondary" type="button" data-payment-overlay-open="payment-history-dialog">Payments / receipts</button>
                @else
                    @if($activeMembership->hasCapability('payments.record_manual') && ! $hasOpenAttempt)<button class="button-secondary" type="button" data-payment-overlay-open="record-payment-dialog">Record payment</button>@endif
                    @if($activeMembership->hasCapability('payments.collect'))<button class="button-primary" type="button" data-payment-overlay-open="secure-payment-dialog">Pay securely</button>@endif
                @endif
            @endif
        @endif

        <details class="relative" data-invoice-more-actions>
            <summary class="button-secondary cursor-pointer list-none px-4" aria-label="More invoice actions">More <span aria-hidden="true">&hellip;</span></summary>
            <div class="invoice-command-menu">
                <label class="sr-only" for="command-invoice-number-{{ $invoice->id }}">Invoice number</label>
                <input class="sr-only" id="command-invoice-number-{{ $invoice->id }}" value="{{ $invoice->invoice_number }}" readonly>
                <button class="invoice-command-menu-item" type="button" data-copy-target="command-invoice-number-{{ $invoice->id }}">Copy invoice number</button>
                @if($invoice->serviceTicket)<a class="invoice-command-menu-item" href="{{ route('office.service-tickets.show', $invoice->serviceTicket) }}#history">Ticket history</a>@endif
                @if($canDeleteDraft)<a class="invoice-command-menu-item text-red-800" href="#delete-unissued-invoice">Delete unissued invoice</a>@endif
                @if($activeMembership->hasCapability('invoices.void') && $invoice->status !== 'void')<a class="invoice-command-menu-item text-red-800" href="#void-reissue">Void &amp; reissue</a>@endif
            </div>
        </details>
    </div>
</section>
