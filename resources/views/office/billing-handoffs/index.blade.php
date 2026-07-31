<x-layouts.office title="Billing handoffs">
    @if(session('status'))
        <div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
    @endif
    <div>
        <p class="text-sm font-bold text-brand-blue">Billing</p>
        <h1 class="mt-1 text-3xl font-bold">Handoff queue</h1>
        <p class="mt-2 text-slate-600">Every completed Service Ticket appears here, including warranty and no-charge work.</p>
    </div>
    <form method="GET" class="surface mt-6 flex flex-wrap items-end gap-4 p-4">
        <div><label class="form-label" for="status">Status</label><select class="form-input" id="status" name="status"><option value="">All</option><option value="ready" @selected(request('status')==='ready')>Ready</option><option value="handed_off" @selected(request('status')==='handed_off')>Handed off</option></select></div>
        <button class="button-primary">Apply</button>
    </form>
    <div class="surface mt-6 overflow-hidden">
        @forelse($handoffs as $handoff)
            @php
                $review = $handoff->closeout->reviews->firstWhere('decision','approved');
                $partAdjustments = $review?->adjustments?->where('type','part')->keyBy('visit_part_proposal_id') ?? collect();
                $effectiveParts = $handoff->closeout->parts->whereNull('removed_at')->reject(fn($part) => $partAdjustments->get($part->id)?->excluded);
            @endphp
            <article class="border-b border-slate-200 p-5 last:border-0">
                <div class="flex flex-wrap justify-between gap-4">
                    <div>
                        <a class="font-bold text-brand-blue" href="{{ route('office.service-tickets.show',$handoff->serviceTicket) }}">{{ $handoff->serviceTicket->ticket_number }}</a>
                        <p class="mt-1 font-semibold">{{ $handoff->serviceTicket->customer->display_name }} · {{ $handoff->serviceTicket->title }}</p>
                        <p class="mt-2 text-sm text-slate-600">Approved time: {{ $handoff->approved_time_minutes }} minutes · Effective proposals: {{ $handoff->approved_parts_count }}</p>
                        @foreach($effectiveParts as $part)
                            @php($adjustment = $partAdjustments->get($part->id))
                            <p class="mt-2 text-sm"><strong>{{ $part->description }}</strong> · {{ $adjustment?->approved_quantity ?? $part->quantity }} {{ $adjustment?->approved_unit ?? $part->unit }} · {{ ucfirst(str_replace('_',' ',$adjustment?->approved_billing_treatment ?? $part->billing_treatment)) }}</p>
                        @endforeach
                    </div>
                    <div class="text-right">
                        <p class="font-bold {{ $handoff->status==='ready' ? 'text-orange-700' : 'text-emerald-700' }}">{{ ucfirst(str_replace('_',' ',$handoff->status)) }}</p>
                        @if($handoff->handed_off_at)<p class="mt-1 text-xs text-slate-500">{{ $handoff->handedOffBy?->name ?? 'Former user' }} · <x-local-time :value="$handoff->handed_off_at" :timezone="$activeOrganization->timezone" /></p>@endif
                        @if($handoff->status==='ready' && $activeMembership->hasCapability('billing_handoffs.manage'))
                            <form method="POST" action="{{ route('office.billing-handoffs.acknowledge',$handoff) }}" class="mt-3">@csrf<input type="hidden" name="acknowledgment_token" value="{{ Str::uuid() }}"><button class="button-primary">Mark handed off</button></form>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="p-8 text-center"><p class="font-bold">No billing handoffs</p><p class="mt-2 text-sm text-slate-500">Approved completed work will appear here.</p></div>
        @endforelse
    </div>
    <div class="mt-5">{{ $handoffs->links() }}</div>
</x-layouts.office>
