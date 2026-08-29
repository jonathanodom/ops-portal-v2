<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $publication->snapshot['document']['number'] }} · NewDay Tech Proposal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 text-slate-950 antialiased">
@php
    $snapshot = $publication->snapshot;
    $computedLines = collect($totals['lines'])->keyBy('id');
    $actionable = $publication->status === 'active';
    $recipientEmail = $access->recipient?->email;
@endphp
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <img class="h-10 w-auto" src="{{ asset('images/newday-logo.png') }}" alt="NewDay Tech">
        <div class="text-right"><p class="text-xs font-bold uppercase tracking-[.12em] text-brand-blue">Proposal</p><p class="font-bold">{{ $snapshot['document']['number'] }}</p></div>
    </div>
</header>
<main class="mx-auto max-w-6xl space-y-6 px-4 py-6 sm:px-6 lg:py-10">
    @if(session('status'))<div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-lg border border-red-300 bg-red-50 p-4" role="alert"><p class="font-bold">Please correct the highlighted information.</p><ul class="mt-2 list-disc pl-5 text-sm">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @if(!$actionable)
        <section class="rounded-lg border border-amber-300 bg-amber-50 p-5" aria-labelledby="proposal-state"><h1 id="proposal-state" class="text-xl font-bold">{{ str($publication->status)->replace('_', ' ')->headline() }}</h1><p class="mt-2 text-sm text-slate-700">This immutable Proposal remains available for reference, but responses and acceptance are disabled.</p></section>
    @endif

    <section class="surface p-5 sm:p-8">
        <p class="text-sm font-bold uppercase tracking-[.12em] text-brand-blue">Prepared for {{ $snapshot['customer']['display_name'] }}</p>
        <h1 class="mt-2 text-3xl font-bold sm:text-4xl">{{ $snapshot['document']['title'] }}</h1>
        <p class="mt-3 text-slate-600">{{ $snapshot['customer']['site_name'] ?: 'Customer-wide proposal' }} · Expires {{ $publication->expires_at->timezone($publication->revision->document->opportunity->organization->timezone)->format('M j, Y') }}</p>
        @if($publication->pdf_status === 'ready')<a class="button-secondary mt-5" href="{{ route('proposals.pdf', $token) }}">Download PDF</a>@endif
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="space-y-6">
            @if($snapshot['sections'] !== [])
                <section class="surface p-5 sm:p-7" aria-labelledby="scope-heading"><h2 id="scope-heading" class="text-2xl font-bold">Scope of work</h2>@foreach($snapshot['sections'] as $section)<article id="section-{{ $section['id'] }}" class="mt-5 border-t border-slate-200 pt-5 first:border-0 first:pt-0"><h3 class="text-lg font-bold">{{ $section['heading'] }}</h3><p class="mt-2 whitespace-pre-line text-slate-700">{{ $section['body'] }}</p></article>@endforeach</section>
            @endif

            <section class="surface overflow-hidden" aria-labelledby="pricing-heading">
                <header class="border-b border-slate-200 p-5 sm:p-7"><h2 id="pricing-heading" class="text-2xl font-bold">Scope and pricing</h2><p class="mt-1 text-sm text-slate-600">Optional selections update the authoritative Proposal total.</p></header>
                <form id="proposal-options" method="POST" action="{{ route('proposals.options', $token) }}">@csrf
                    <div class="divide-y divide-slate-200">
                        @foreach($snapshot['lines'] as $line)
                            @php($computed = $computedLines->get($line['id']))
                            <article id="line-{{ $line['id'] }}" class="p-5 sm:p-6">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0"><h3 class="font-bold">{{ $line['description'] }}</h3>@if($line['customer_description'])<p class="mt-1 text-sm text-slate-600">{{ $line['customer_description'] }}</p>@endif<p class="mt-1 text-xs text-slate-500">{{ rtrim(rtrim(number_format($line['quantity_millis'] / 1000, 3, '.', ''), '0'), '.') }} {{ $line['unit_name'] }}@if($line['location']) · {{ $line['location'] }}@endif</p></div>
                                    @if($snapshot['publication']['show_line_details'])<strong class="text-lg">${{ number_format(($computed['total_cents'] ?? 0) / 100, 2) }}</strong>@endif
                                </div>
                                @if($line['optional'])<label class="mt-4 flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-slate-300 px-3 py-2"><input class="h-5 w-5" type="checkbox" name="options[]" value="{{ $line['id'] }}" @checked($computed['included'] ?? false) @disabled(!$actionable)><span class="font-semibold">Include this option</span></label>@endif
                                @if($snapshot['publication']['show_package_components'] && $line['components'] !== [])<ul class="mt-3 list-disc pl-5 text-sm text-slate-600">@foreach($line['components'] as $component)<li>{{ $component['name'] }}</li>@endforeach</ul>@endif
                            </article>
                        @endforeach
                    </div>
                    @if($actionable && collect($snapshot['lines'])->contains('optional', true))<div class="border-t border-slate-200 p-4"><button class="button-secondary w-full sm:w-auto">Save selected options</button><p id="option-status" class="mt-2 text-sm text-slate-600" aria-live="polite"></p></div>@endif
                </form>
                <dl class="border-t border-slate-200 bg-slate-50 p-5 sm:ml-auto sm:max-w-md sm:p-6">
                    <div class="flex justify-between py-1"><dt>Subtotal</dt><dd id="proposal-subtotal">${{ number_format($totals['subtotal_cents'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between py-1"><dt>Discount</dt><dd id="proposal-discount">−${{ number_format($totals['discount_cents'] / 100, 2) }}</dd></div>
                    <div class="flex justify-between py-1"><dt>Tax</dt><dd id="proposal-tax">${{ number_format($totals['tax_cents'] / 100, 2) }}</dd></div>
                    <div class="mt-2 flex justify-between border-t border-slate-300 pt-3 text-xl font-bold"><dt>Total</dt><dd id="proposal-total">${{ number_format($totals['total_cents'] / 100, 2) }}</dd></div>
                </dl>
            </section>

            @if($snapshot['media'] !== [])<section class="surface p-5 sm:p-7"><h2 class="text-2xl font-bold">Documents and media</h2><ul class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200">@foreach($snapshot['media'] as $media)<li class="p-4"><p class="font-semibold">{{ $media['caption'] ?: ($media['original_name'] ?: 'Video') }}</p>@if($media['media_type'] === 'video')<a class="mt-2 inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ $media['embed_url'] }}" target="_blank" rel="noreferrer">Open video</a>@else<a class="mt-2 inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ route('proposals.media', [$token, $media['id']]) }}">Open {{ $media['media_type'] }}</a>@endif</li>@endforeach</ul></section>@endif

            <section class="surface p-5 sm:p-7"><h2 class="text-2xl font-bold">Terms</h2><p class="mt-4 whitespace-pre-line text-sm text-slate-700">{{ $snapshot['terms']['body'] }}</p></section>
        </div>

        <aside class="space-y-6">
            <section class="surface p-5"><h2 class="text-xl font-bold">Payment schedule</h2>@forelse($snapshot['milestones'] as $milestone)<div class="mt-3 flex justify-between gap-3 border-t border-slate-200 pt-3"><span>{{ $milestone['name'] }}</span><strong>@if($milestone['amount_type'] === 'percent'){{ number_format($milestone['amount_value'] / 100, 2) }}%@else${{ number_format($milestone['amount_value'] / 100, 2) }}@endif</strong></div>@empty<p class="mt-2 text-sm text-slate-500">No payment schedule.</p>@endforelse</section>

            <section class="surface p-5"><h2 class="text-xl font-bold">Discussion</h2><div class="mt-4 space-y-3">@forelse($publication->comments as $comment)<article class="rounded-lg border border-slate-200 p-3"><p class="text-xs font-bold uppercase text-slate-500">{{ $comment->author_type === 'staff' ? ($comment->staffUser?->name ?? 'NewDay Tech') : ($comment->author_name ?: 'Customer') }}</p><p class="mt-1 whitespace-pre-line text-sm">{{ $comment->body }}</p></article>@empty<p class="text-sm text-slate-500">No comments yet.</p>@endforelse</div>@if($actionable)<form method="POST" action="{{ route('proposals.comments.store', $token) }}" class="mt-5 space-y-3">@csrf<input type="hidden" name="target_type" value="proposal"><label class="form-label" for="comment-name">Your name</label><input class="form-input" id="comment-name" name="name" required><label class="form-label" for="comment-email">Email (optional)</label><input class="form-input" id="comment-email" name="email" type="email"><label class="form-label" for="comment-body">Comment</label><textarea class="form-textarea" id="comment-body" name="body" required></textarea><button class="button-secondary w-full">Add comment</button></form>@endif</section>

            @if($actionable)<section class="surface p-5"><h2 class="text-xl font-bold">Request changes</h2><p class="mt-1 text-sm text-slate-600">This makes the current Proposal view-only and sends your notes to NewDay Tech.</p><form method="POST" action="{{ route('proposals.request-changes', $token) }}" class="mt-4 space-y-3">@csrf<label class="form-label" for="change-name">Your name</label><input class="form-input" id="change-name" name="name" required><label class="form-label" for="change-email">Email (optional)</label><input class="form-input" id="change-email" name="email" type="email"><label class="form-label" for="change-body">Requested changes</label><textarea class="form-textarea" id="change-body" name="body" required></textarea><label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="confirm" value="1" required><span>I understand this version becomes view-only.</span></label><button class="button-secondary w-full">Request changes</button></form></section>@endif
        </aside>
    </div>

    @if($actionable && $publication->acceptance_enabled)
        <section class="surface p-5 sm:p-8" aria-labelledby="accept-heading">
            <h2 id="accept-heading" class="text-2xl font-bold">Accept Proposal</h2><p class="mt-2 text-slate-600">One authorized signature accepts the selected scope and freezes the commercial record. Any scheduled deposit is prepared as a draft for Office review; no payment is created here.</p>
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <form method="POST" action="{{ route('proposals.verifications.store', $token) }}" class="rounded-lg border border-slate-200 p-4">@csrf<label class="form-label" for="verify-email">Signer email verification</label><input class="form-input" id="verify-email" type="email" name="email" value="{{ old('signer_email', $recipientEmail) }}" required><button class="button-secondary mt-3 w-full">Send verification code</button><p class="mt-2 text-xs text-slate-500">Recipient links using their assigned email do not require a code. Generic links and changed emails do.</p></form>
                    @if(session('proposal_verification_id'))<form method="POST" action="{{ route('proposals.verifications.verify', [$token, session('proposal_verification_id')]) }}" class="mt-4 rounded-lg border border-brand-blue p-4">@csrf<label class="form-label" for="verification-code">Six-digit code</label><input class="form-input" id="verification-code" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" required><button class="button-primary mt-3 w-full">Verify email</button></form>@endif
                </div>
                <form id="acceptance-form" method="POST" action="{{ route('proposals.accept', $token) }}" class="space-y-4">@csrf<input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', (string) Str::uuid()) }}"><input type="hidden" name="verification_id" value="{{ $verifiedId }}"><input id="signature-data" type="hidden" name="signature_data" value="{{ old('signature_data') }}"><label class="form-label" for="signer-name">Full name</label><input class="form-input" id="signer-name" name="signer_name" value="{{ old('signer_name') }}" required><label class="form-label" for="signer-title">Title or position</label><input class="form-input" id="signer-title" name="signer_title" value="{{ old('signer_title') }}" required><label class="form-label" for="signer-email">Email</label><input class="form-input" id="signer-email" name="signer_email" type="email" value="{{ old('signer_email', $recipientEmail) }}" required><fieldset><legend class="form-label">Draw signature</legend><canvas id="signature-pad" class="mt-2 h-44 w-full touch-none rounded-lg border-2 border-slate-400 bg-white" width="800" height="240" aria-label="Signature drawing area"></canvas><button id="clear-signature" class="button-secondary mt-2" type="button">Clear signature</button></fieldset><label class="flex min-h-11 items-start gap-3 rounded-lg border border-slate-300 p-3"><input class="mt-1 h-5 w-5" type="checkbox" name="consent" value="1" required><span>{{ $snapshot['acceptance']['statement'] }}</span></label><button class="button-primary min-h-12 w-full">Accept and sign Proposal</button></form>
            </div>
        </section>
    @endif
</main>
<footer class="border-t border-slate-200 bg-white"><div class="mx-auto max-w-6xl px-4 py-6 text-sm text-slate-500 sm:px-6">Prepared by {{ $snapshot['seller']['name'] }} · Secure Proposal</div></footer>
<script>
(() => {
    const options = document.getElementById('proposal-options');
    if (options) options.addEventListener('change', async () => {
        const status = document.getElementById('option-status'); status.textContent = 'Saving selections…';
        try {
            const response = await fetch(options.action, {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:new FormData(options)});
            if (!response.ok) throw new Error('save'); const totals = await response.json();
            document.getElementById('proposal-subtotal').textContent = totals.subtotal;
            document.getElementById('proposal-discount').textContent = '−' + totals.discount;
            document.getElementById('proposal-tax').textContent = totals.tax;
            document.getElementById('proposal-total').textContent = totals.total;
            status.textContent = 'Selections saved.';
        } catch (_) { status.textContent = navigator.onLine ? 'Selections were not saved. Retry.' : 'Offline. Reconnect and retry.'; }
    });
    const canvas = document.getElementById('signature-pad'); if (!canvas) return;
    const ctx = canvas.getContext('2d'); ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a'; let drawing = false;
    const point = event => { const r=canvas.getBoundingClientRect(); const p=event.touches?.[0] ?? event; return [(p.clientX-r.left)*(canvas.width/r.width),(p.clientY-r.top)*(canvas.height/r.height)]; };
    const start = event => { drawing=true; const [x,y]=point(event); ctx.beginPath(); ctx.moveTo(x,y); event.preventDefault(); };
    const move = event => { if(!drawing)return; const [x,y]=point(event); ctx.lineTo(x,y); ctx.stroke(); event.preventDefault(); };
    ['pointerdown','touchstart'].forEach(e=>canvas.addEventListener(e,start,{passive:false})); ['pointermove','touchmove'].forEach(e=>canvas.addEventListener(e,move,{passive:false})); ['pointerup','pointercancel','touchend'].forEach(e=>canvas.addEventListener(e,()=>drawing=false));
    document.getElementById('clear-signature').addEventListener('click',()=>ctx.clearRect(0,0,canvas.width,canvas.height));
    document.getElementById('acceptance-form').addEventListener('submit',()=>document.getElementById('signature-data').value=canvas.toDataURL('image/png'));
})();
</script>
</body>
</html>
