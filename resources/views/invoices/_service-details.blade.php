@php
    $visits = collect($serviceContext['visits'] ?? []);
    $workItems = collect($serviceContext['work_items'] ?? []);
    $followUpCount = $workItems->whereNotNull('follow_up_ticket')->count();
@endphp
<section class="surface mt-6 overflow-hidden" aria-labelledby="invoice-service-details-heading">
    <div class="border-b border-slate-200 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 id="invoice-service-details-heading" class="text-xl font-bold">Service Details{{ $serviceContextMode === 'locked' ? ' — locked at issuance' : '' }}</h2>
                @if($serviceContextMode === 'live')
                    <p class="mt-1 text-sm font-semibold text-brand-blue">Live preview — captured when Invoice is issued</p>
                @elseif($serviceContextMode === 'locked' && $capturedAt)
                    <p class="mt-1 text-sm text-slate-600">Captured <x-local-time :value="$capturedAt" :timezone="$activeOrganization->timezone" /></p>
                @elseif($serviceContextMode === 'legacy')
                    <p class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Detailed Service Ticket context was not snapshotted when this Invoice was issued.</p>
                @endif
            </div>
            @if($ticketUrl)<a class="button-secondary" href="{{ $ticketUrl }}">View current Service Ticket</a>@endif
        </div>
        @if($serviceContext)
            <p class="mt-4 font-bold text-slate-900">{{ $serviceContext['ticket']['number'] }} · {{ $serviceContext['ticket']['title'] }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ $serviceContext['site']['name'] }}@if($serviceContext['site']['address'] ?? null) · {{ $serviceContext['site']['address'] }}@endif</p>
            @if($serviceContext['contact']['name'] ?? null)<p class="mt-1 text-sm text-slate-600">Point of contact: {{ $serviceContext['contact']['name'] }}@if($serviceContext['contact']['role'] ?? null) · {{ $serviceContext['contact']['role'] }}@endif</p>@endif
            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm sm:max-w-lg">
                <div><dt class="text-slate-500">Visits</dt><dd class="text-lg font-bold">{{ $visits->count() }}</dd></div>
                <div><dt class="text-slate-500">Work Items</dt><dd class="text-lg font-bold">{{ $workItems->count() }}</dd></div>
                <div><dt class="text-slate-500">Follow-up</dt><dd class="text-lg font-bold">{{ $followUpCount }}</dd></div>
            </dl>
        @endif
    </div>
    @if($serviceContext)
        <details class="group" {{ $serviceContextMode === 'customer' ? 'open' : '' }}>
            <summary class="flex min-h-11 cursor-pointer items-center justify-between px-5 py-3 font-bold text-brand-blue focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-blue">View service details <span aria-hidden="true">+</span></summary>
            <div class="space-y-6 border-t border-slate-200 p-5">
                <section aria-labelledby="requested-service-heading"><h3 id="requested-service-heading" class="font-bold">Requested service</h3><p class="mt-2 whitespace-pre-line text-sm">{{ $serviceContext['requested_service']['scope'] ?: ($serviceContext['requested_service']['summary'] ?: 'No customer-facing scope was recorded.') }}</p>@if(($serviceContext['requested_service']['summary'] ?? null) && $serviceContext['requested_service']['summary'] !== ($serviceContext['requested_service']['scope'] ?? null))<p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $serviceContext['requested_service']['summary'] }}</p>@endif</section>
                @if($workItems->isNotEmpty())<section aria-labelledby="service-work-items-heading"><h3 id="service-work-items-heading" class="font-bold">Additional Work Items</h3><div class="mt-3 grid gap-3 sm:grid-cols-2">@foreach($workItems as $item)<article class="rounded-lg border border-slate-200 p-4"><p class="font-semibold break-words">{{ $item['title'] }}</p><p class="mt-1 text-sm text-slate-600">{{ $item['status'] }}</p>@if($item['discovered_visit'] ?? null)<p class="mt-2 text-sm">Discovered during {{ $item['discovered_visit'] }}</p>@endif @if(!empty($item['handled_visits']))<p class="mt-1 text-sm">Handled: {{ implode(', ', $item['handled_visits']) }}</p>@endif @if($item['follow_up_ticket'] ?? null)<p class="mt-1 text-sm font-semibold text-orange-800">Follow-up · {{ $item['follow_up_ticket'] }}</p>@endif</article>@endforeach</div></section>@endif
                @if($visits->isNotEmpty())<section aria-labelledby="service-visits-heading"><h3 id="service-visits-heading" class="font-bold">Visit history</h3><div class="mt-3 space-y-4">@foreach($visits as $visit)<article class="rounded-lg border border-slate-200 p-4"><div class="flex flex-wrap justify-between gap-2"><p class="font-bold">{{ $visit['label'] }}</p><p class="text-sm font-semibold">{{ $visit['status'] }}</p></div>@if($visit['date'] ?? null)<p class="mt-1 text-sm text-slate-600">{{ \Illuminate\Support\Carbon::parse($visit['date'])->format('M j, Y') }}</p>@endif @if(!empty($visit['technicians']))<p class="mt-2 text-sm"><span class="font-semibold">Technicians:</span> {{ implode(', ', $visit['technicians']) }}</p>@endif @if($visit['site_window']['start_at'] ?? null)<p class="mt-1 text-sm"><span class="font-semibold">On site:</span> {{ \Illuminate\Support\Carbon::parse($visit['site_window']['start_at'])->timezone($visit['timezone'])->format('g:i A') }}–{{ \Illuminate\Support\Carbon::parse($visit['site_window']['end_at'])->timezone($visit['timezone'])->format('g:i A T') }}</p>@endif @if($visit['work_performed'] ?? null)<div class="mt-3"><p class="text-sm font-semibold">Work performed</p><p class="mt-1 whitespace-pre-line text-sm">{{ $visit['work_performed'] }}</p></div>@endif @if($visit['recommendations'] ?? null)<div class="mt-3"><p class="text-sm font-semibold">Recommendations</p><p class="mt-1 whitespace-pre-line text-sm">{{ $visit['recommendations'] }}</p></div>@endif @if($visit['outcome'] ?? null)<p class="mt-3 text-sm"><span class="font-semibold">Outcome:</span> {{ $visit['outcome'] }}</p>@endif @if(!empty($visit['parts']))<div class="mt-3"><p class="text-sm font-semibold">Parts and equipment</p><ul class="mt-1 list-disc pl-5 text-sm">@foreach($visit['parts'] as $part)<li>{{ $part['description'] }} · {{ rtrim(rtrim($part['quantity'], '0'), '.') }} {{ $part['unit'] }}</li>@endforeach</ul></div>@endif @php($ack=$visit['acknowledgment'] ?? ['type'=>'none']) @if($ack['type'] !== 'none')<div class="mt-3 border-t border-slate-200 pt-3 text-sm"><p class="font-semibold">Customer acknowledgment</p>@if($ack['type'] === 'signed')<p>{{ $ack['name'] }}@if($ack['role'] ?? null) · {{ $ack['role'] }}@endif · Signed @if($ack['occurred_at'] ?? null){{ \Illuminate\Support\Carbon::parse($ack['occurred_at'])->timezone($visit['timezone'])->format('M j, Y \a\t g:i A T') }}@endif</p>@else<p>{{ $ack['category'] }}@if($ack['detail'] ?? null) · {{ $ack['detail'] }}@endif @if($ack['occurred_at'] ?? null)· Recorded {{ \Illuminate\Support\Carbon::parse($ack['occurred_at'])->timezone($visit['timezone'])->format('M j, Y \a\t g:i A T') }}@endif</p>@endif</div>@endif</article>@endforeach</div></section>@endif
            </div>
        </details>
    @endif
</section>
