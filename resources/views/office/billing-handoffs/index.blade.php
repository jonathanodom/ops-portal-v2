<x-layouts.office title="Billing">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-sm font-bold text-brand-blue">Billing</p><h1 class="mt-1 text-3xl font-bold">Invoice queue</h1><p class="mt-2 text-slate-600">Create and finish one auditable invoice for each completed Service Ticket.</p></div>
        @if($activeMembership->hasCapability('billing.settings.manage'))<a class="button-secondary" href="{{ route('office.billing.settings.edit') }}">Billing settings</a>@endif
    </div>
    <form method="GET" class="surface mt-6 flex flex-wrap items-end gap-4 p-4">
        <div><label class="form-label" for="status">Handoff status</label><select class="form-input" id="status" name="status"><option value="">All</option><option value="ready" @selected(request('status')==='ready')>Ready</option><option value="handed_off" @selected(request('status')==='handed_off')>Invoice started</option></select></div>
        <button class="button-primary">Apply</button>
    </form>
    <div class="surface mt-6 overflow-hidden">
        @forelse($handoffs as $handoff)
            <article class="border-b border-slate-200 p-5 last:border-0">
                <div class="flex flex-wrap justify-between gap-5">
                    <div>
                        <a class="font-bold text-brand-blue" href="{{ route('office.service-tickets.show',$handoff->serviceTicket) }}">{{ $handoff->serviceTicket->ticket_number }}</a>
                        <p class="mt-1 font-semibold">{{ $handoff->serviceTicket->customer->display_name }} · {{ $handoff->serviceTicket->title }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $handoff->serviceTicket->serviceLocation->name }} · Approved <x-local-time :value="$handoff->created_at" :timezone="$activeOrganization->timezone" /></p>
                    </div>
                    <div class="min-w-52 text-right">
                        @if($handoff->currentInvoice)
                            <p class="font-bold text-brand-blue">{{ $handoff->currentInvoice->invoice_number }}</p>
                            <p class="mt-1 text-sm font-semibold">{{ ucfirst(str_replace('_',' ',$handoff->currentInvoice->status)) }} · ${{ number_format($handoff->currentInvoice->total_cents / 100, 2) }}</p>
                            <a class="button-primary mt-3" href="{{ route('office.invoices.show',$handoff->currentInvoice) }}">Open invoice</a>
                        @elseif($handoff->status==='ready' && $activeMembership->hasCapability('invoices.manage'))
                            <p class="font-bold text-orange-700">Ready to invoice</p>
                            <form method="POST" action="{{ route('office.billing-handoffs.invoice.store',$handoff) }}" class="mt-3">@csrf<input type="hidden" name="creation_token" value="{{ Str::uuid() }}"><button class="button-primary">Create invoice</button></form>
                        @else
                            <p class="font-bold text-slate-600">{{ ucfirst(str_replace('_',' ',$handoff->status)) }}</p>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="p-8 text-center"><p class="font-bold">No billing work</p><p class="mt-2 text-sm text-slate-500">Completed, approved Service Tickets will appear here.</p></div>
        @endforelse
    </div>
    <div class="mt-5">{{ $handoffs->links() }}</div>
</x-layouts.office>
