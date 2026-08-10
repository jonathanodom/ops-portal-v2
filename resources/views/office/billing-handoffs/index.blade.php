<x-layouts.office title="Billing" width="workspace">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <x-office.page-header title="Invoice queue" description="Create invoices, monitor balances, and continue payment collection for completed Service Tickets." eyebrow="Billing">
        @if($activeMembership->hasCapability('billing.settings.manage'))
            <x-slot:actions><a class="button-secondary" href="{{ route('office.settings.billing.edit') }}">Billing settings</a></x-slot:actions>
        @endif
    </x-office.page-header>

    <form method="GET" class="office-filter-toolbar sm:grid-cols-[minmax(220px,320px)_auto]" aria-label="Billing queue filters">
        <div><label class="form-label" for="status">Handoff status</label><select class="form-input" id="status" name="status"><option value="">All handoffs</option><option value="ready" @selected(request('status')==='ready')>Ready to invoice</option><option value="handed_off" @selected(request('status')==='handed_off')>Invoice started</option></select></div>
        <div class="flex flex-wrap gap-2"><button class="button-secondary">Filter</button>@if(request()->has('status'))<a href="{{ route('office.billing-handoffs.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-brand-blue underline">Clear</a>@endif</div>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table">
            <caption class="sr-only">Invoice and payment queue</caption>
            <thead><tr><th scope="col">Service Ticket</th><th scope="col">Customer and location</th><th scope="col">Approved</th><th scope="col">Invoice</th><th scope="col">Amount</th><th scope="col">Payment</th><th scope="col" class="text-right">Next action</th></tr></thead>
            <tbody>
                @forelse($handoffs as $handoff)
                    <tr>
                        <td><a class="font-bold text-brand-blue hover:text-brand-blue-deep" href="{{ route('office.service-tickets.show',$handoff->serviceTicket) }}">{{ $handoff->serviceTicket->ticket_number }}</a><p class="mt-0.5 max-w-sm text-xs text-slate-500">{{ $handoff->serviceTicket->title }}</p></td>
                        <td><p class="font-semibold text-slate-900">{{ $handoff->serviceTicket->customer->display_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $handoff->serviceTicket->serviceLocation->name }}</p></td>
                        <td><x-local-time :value="$handoff->created_at" :timezone="$activeOrganization->timezone" /></td>
                        @if($handoff->currentInvoice)
                            <td><p class="font-bold text-slate-950">{{ $handoff->currentInvoice->invoice_number }}</p><p class="mt-0.5 text-xs text-slate-500">{{ ucfirst(str_replace('_',' ',$handoff->currentInvoice->status)) }}</p></td>
                            <td>${{ number_format($handoff->currentInvoice->total_cents/100,2) }}</td>
                            <td><p class="font-semibold text-slate-900">{{ ucfirst(str_replace('_',' ',$handoff->currentInvoice->paymentState())) }}</p><p class="mt-0.5 text-xs text-slate-500">Balance ${{ number_format(max(0,$handoff->currentInvoice->balanceCents())/100,2) }}</p></td>
                            <td class="text-right"><a class="inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ route('office.invoices.show',$handoff->currentInvoice) }}">Open invoice<span class="sr-only"> {{ $handoff->currentInvoice->invoice_number }}</span></a></td>
                        @else
                            <td><span class="status-priority">Ready to invoice</span></td><td>&mdash;</td><td>Not started</td>
                            <td class="text-right">
                                @if($handoff->status==='ready' && $activeMembership->hasCapability('invoices.manage'))
                                    <form method="POST" action="{{ route('office.billing-handoffs.invoice.store',$handoff) }}">@csrf<input type="hidden" name="creation_token" value="{{ Str::uuid() }}"><button class="button-primary">Create invoice</button></form>
                                @else
                                    <span class="font-semibold text-slate-600">{{ ucfirst(str_replace('_',' ',$handoff->status)) }}</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center"><p class="font-bold text-slate-900">No billing work</p><p class="mt-1 text-sm text-slate-500">Completed, approved Service Tickets will appear here.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @forelse($handoffs as $handoff)
            <article class="office-mobile-card">
                <div class="flex items-start justify-between gap-3">
                    <div><a class="font-bold text-brand-blue" href="{{ route('office.service-tickets.show',$handoff->serviceTicket) }}">{{ $handoff->serviceTicket->ticket_number }}</a><p class="mt-1 font-semibold text-slate-950">{{ $handoff->serviceTicket->customer->display_name }}</p></div>
                    @if($handoff->currentInvoice)<span class="status-active">{{ ucfirst(str_replace('_',' ',$handoff->currentInvoice->status)) }}</span>@else<span class="status-priority">Ready</span>@endif
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ $handoff->serviceTicket->serviceLocation->name }}</p>
                @if($handoff->currentInvoice)
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="font-semibold text-slate-500">Invoice</dt><dd class="mt-0.5 font-bold text-slate-900">{{ $handoff->currentInvoice->invoice_number }}</dd></div><div><dt class="font-semibold text-slate-500">Balance</dt><dd class="mt-0.5 font-bold text-slate-900">${{ number_format(max(0,$handoff->currentInvoice->balanceCents())/100,2) }}</dd></div></dl>
                    <a class="button-primary mt-4 w-full" href="{{ route('office.invoices.show',$handoff->currentInvoice) }}">Open invoice</a>
                @elseif($handoff->status==='ready' && $activeMembership->hasCapability('invoices.manage'))
                    <form method="POST" action="{{ route('office.billing-handoffs.invoice.store',$handoff) }}" class="mt-4">@csrf<input type="hidden" name="creation_token" value="{{ Str::uuid() }}"><button class="button-primary w-full">Create invoice</button></form>
                @endif
            </article>
        @empty
            <div class="surface p-8 text-center"><p class="font-bold text-slate-900">No billing work</p><p class="mt-1 text-sm text-slate-500">Completed, approved Service Tickets will appear here.</p></div>
        @endforelse
    </div>
    <div class="mt-5">{{ $handoffs->links() }}</div>
</x-layouts.office>
