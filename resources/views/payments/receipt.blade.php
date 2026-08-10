<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Receipt {{ $receipt->invoice->invoice_number }}</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-900">
<main class="mx-auto max-w-2xl"><section class="surface p-6 sm:p-8">
    <img src="{{ $receipt->invoice->seller_logo_asset_id ? route('payments.receipts.brand',['receipt'=>$receipt,'token'=>$token]) : asset('images/newday-logo.png') }}" alt="{{ $receipt->invoice->seller_name ?: $receipt->invoice->organization->name }}" class="h-12 w-auto">
    <p class="mt-6 text-sm font-bold uppercase tracking-wider text-brand-blue">Payment receipt</p><h1 class="mt-1 text-3xl font-bold">{{ $receipt->invoice->invoice_number }}</h1>
    <dl class="mt-6 space-y-3"><div class="flex justify-between gap-4"><dt>Date</dt><dd class="font-bold"><x-local-time :value="$receipt->transaction->received_at" :timezone="$receipt->invoice->organization->timezone" /></dd></div><div class="flex justify-between gap-4"><dt>Method</dt><dd class="font-bold">{{ ucfirst($receipt->transaction->method) }}</dd></div><div class="flex justify-between gap-4 border-t border-slate-200 pt-4 text-xl"><dt>{{ $receipt->transaction->type==='payment' ? 'Payment' : 'Refund' }}</dt><dd class="font-bold">${{ number_format($receipt->transaction->amount_cents/100,2) }}</dd></div><div class="flex justify-between gap-4"><dt>Remaining balance</dt><dd class="font-bold">${{ number_format(max(0,$receipt->invoice->balanceCents())/100,2) }}</dd></div></dl>
    @if($receipt->pdf_status==='ready')<a class="button-primary mt-6 w-full" href="{{ route('payments.receipts.pdf',['receipt'=>$receipt,'token'=>$token]) }}">Download receipt PDF</a>@endif
</section></main></body></html>
