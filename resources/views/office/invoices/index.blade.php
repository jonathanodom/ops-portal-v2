<x-layouts.office title="Billing / Invoices" width="workspace">
    <x-office.page-header title="Billing / Invoices" description="Create invoices from approved work, prepare direct invoices, and manage balances in one workspace.">
        @if($activeMembership->hasCapability('invoices.manage') || $activeMembership->hasCapability('billing.settings.manage'))
            <x-slot:actions>
                @if($activeMembership->hasCapability('invoices.manage'))<a class="button-primary" href="{{ route('office.invoices.create') }}">New invoice</a>@endif
                @if($activeMembership->hasCapability('billing.settings.manage'))<a class="button-secondary" href="{{ route('office.settings.billing.edit') }}">Billing settings</a>@endif
            </x-slot:actions>
        @endif
    </x-office.page-header>
    <nav class="office-workspace-tabs" aria-label="Billing and invoice status">
        @foreach(['all'=>'All','ready_to_invoice'=>'Ready to Invoice','draft'=>'Draft','ready_for_review'=>'Ready for Review','issued'=>'Issued','paid'=>'Paid'] as $value=>$label)
            <a href="{{ route('office.invoices.index', array_merge(request()->except(['workspace','page']), ['workspace'=>$value])) }}" @if(request('workspace', 'all') === $value) aria-current="page" @endif class="office-workspace-tab {{ request('workspace', 'all') === $value ? 'office-workspace-tab-active' : '' }}">{{ $label }}@if($value==='ready_to_invoice' && $readyHandoffCount)<span class="ml-1" aria-label="{{ $readyHandoffCount }} ready">({{ $readyHandoffCount }})</span>@endif</a>
        @endforeach
    </nav>

    <div class="mt-5"><h2 class="text-xl font-bold text-slate-950">Billing activity</h2><p class="mt-1 text-sm text-slate-600">Ready work and every invoice state share this organization-scoped ledger.</p></div>

    <form method="GET" class="office-filter-toolbar lg:grid-cols-3 xl:grid-cols-[minmax(180px,0.7fr)_minmax(220px,1fr)_minmax(220px,1fr)_auto]" aria-label="Invoice filters">
        <input type="hidden" name="workspace" value="{{ request('workspace', 'all') }}">
        <div><label class="form-label" for="invoice">Invoice number</label><input class="form-input" id="invoice" name="invoice" value="{{ request('invoice') }}" placeholder="NDT-INV-"></div>
        <div><label class="form-label" for="customer">Customer</label><input class="form-input" id="customer" name="customer" value="{{ request('customer') }}" placeholder="Display or legal name"></div>
        <div><label class="form-label" for="ticket">Ticket / Project</label><input class="form-input" id="ticket" name="ticket" value="{{ request('ticket') }}" placeholder="Ticket number or title"></div>
        <div class="flex flex-wrap gap-2"><button class="button-secondary">Filter</button>@if(request()->query())<a href="{{ route('office.invoices.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-brand-blue underline">Clear</a>@endif</div>
        <details class="lg:col-span-3 xl:col-span-4" @if(request()->hasAny(['status','payment_state','balance_state','date_from','date_to','sort','direction'])) open @endif>
            <summary class="inline-flex min-h-11 cursor-pointer items-center font-bold text-brand-blue">More filters</summary>
            <div class="mt-2 grid gap-3 border-t border-slate-200 pt-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                <div><label class="form-label" for="status">Invoice status</label><select class="form-input" id="status" name="status"><option value="">All statuses</option>@foreach(['draft'=>'Draft','ready_for_review'=>'Ready for review','issued'=>'Issued','void'=>'Void'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="form-label" for="payment_state">Payment state</label><select class="form-input" id="payment_state" name="payment_state"><option value="">All payment states</option>@foreach(['unpaid','partially_paid','paid','partially_refunded','refunded','overpaid'] as $value)<option value="{{ $value }}" @selected(request('payment_state')===$value)>{{ Str::headline($value) }}</option>@endforeach</select></div>
                <div><label class="form-label" for="balance_state">Balance</label><select class="form-input" id="balance_state" name="balance_state"><option value="">Any balance</option><option value="open" @selected(request('balance_state')==='open')>Open balance</option><option value="paid" @selected(request('balance_state')==='paid')>Paid / no balance</option><option value="overdue" @selected(request('balance_state')==='overdue')>Overdue</option></select></div>
                <div><label class="form-label" for="date_from">Date from</label><input class="form-input" id="date_from" name="date_from" type="date" value="{{ request('date_from') }}"></div>
                <div><label class="form-label" for="date_to">Date to</label><input class="form-input" id="date_to" name="date_to" type="date" value="{{ request('date_to') }}"></div>
                <div><label class="form-label" for="sort">Sort by</label><select class="form-input" id="sort" name="sort">@foreach(['date'=>'Date','invoice'=>'Invoice #','customer'=>'Customer','ticket'=>'Ticket / Project','status'=>'Status','due'=>'Due date','total'=>'Total','balance'=>'Balance'] as $value=>$label)<option value="{{ $value }}" @selected(request('sort','date')===$value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="form-label" for="direction">Direction</label><select class="form-input" id="direction" name="direction"><option value="desc" @selected(request('direction','desc')==='desc')>Newest / high first</option><option value="asc" @selected(request('direction')==='asc')>Oldest / low first</option></select></div>
            </div>
        </details>
    </form>

    <div class="office-table-wrap" data-office-table>
        <table class="office-data-table invoice-index-table">
            <caption class="sr-only">Organization invoice ledger</caption>
            <thead><tr><th scope="col">Date</th><th scope="col">Invoice / Work</th><th scope="col">Customer</th><th scope="col">Ticket / Project</th><th scope="col">Status</th><th scope="col">Due date</th><th scope="col" class="text-right">Total</th><th scope="col" class="text-right">Balance / Action</th></tr></thead>
            <tbody>
                @foreach($readyHandoffs as $handoff)
                    <tr data-ready-handoff-row>
                        <td><x-local-time :value="$handoff->created_at" :timezone="$activeOrganization->timezone" format="M j, Y" /></td>
                        <td><p class="font-bold text-slate-950">{{ $handoff->serviceTicket->ticket_number }}</p><p class="mt-0.5 text-xs text-slate-500">Approved work</p></td>
                        <td><p class="font-semibold text-slate-950">{{ $handoff->serviceTicket->customer->display_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $handoff->serviceTicket->serviceLocation->name }}</p></td>
                        <td><a class="font-bold text-slate-900 hover:text-brand-blue" href="{{ route('office.service-tickets.show',$handoff->serviceTicket) }}">{{ $handoff->serviceTicket->title }}</a><p class="mt-0.5 text-xs text-slate-500">{{ $handoff->approved_time_minutes }} approved minutes</p></td>
                        <td><span class="status-priority">Ready to Invoice</span></td>
                        <td>&mdash;</td><td class="text-right">&mdash;</td>
                        <td class="text-right">@if($activeMembership->hasCapability('invoices.manage'))<form method="POST" action="{{ route('office.billing-handoffs.invoice.store',$handoff) }}">@csrf<input type="hidden" name="creation_token" value="{{ Str::uuid() }}"><button class="button-primary">Create invoice</button></form>@else<span class="text-sm text-slate-500">Ready</span>@endif</td>
                    </tr>
                @endforeach
                @foreach($invoices as $invoice)
                    @php
                        $balance = max(0, $invoice->balanceCents());
                        $statusClass = match($invoice->status) {'issued'=>'status-active','void'=>'status-inactive','ready_for_review'=>'status-priority',default=>'status-hold'};
                        $isOverdue = $invoice->status === 'issued' && $invoice->due_on && $invoice->due_on->lt(today($activeOrganization->timezone)) && $balance > 0;
                    @endphp
                    <tr data-invoice-row>
                        <td><x-local-time :value="$invoice->issued_at ?? $invoice->created_at" :timezone="$activeOrganization->timezone" format="M j, Y" /></td>
                        <td><a class="inline-flex min-h-11 items-center font-bold text-brand-blue hover:text-brand-blue-deep" href="{{ route('office.invoices.show',$invoice) }}">{{ $invoice->invoice_number }}</a></td>
                        <td><p class="font-semibold text-slate-950">{{ $invoice->customer->display_name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ $invoice->serviceLocation->name }}</p></td>
                        <td>@if($invoice->serviceTicket)<a class="font-bold text-slate-900 hover:text-brand-blue" href="{{ route('office.service-tickets.show',$invoice->serviceTicket) }}">{{ $invoice->serviceTicket->ticket_number }}</a><p class="mt-0.5 max-w-xs truncate text-xs text-slate-500">{{ $invoice->serviceTicket->title }}</p>@else<span class="font-bold text-slate-900">Direct invoice</span><p class="mt-0.5 text-xs text-slate-500">No Service Ticket</p>@endif</td>
                        <td><span class="{{ $statusClass }}">{{ Str::headline($invoice->status) }}</span><p class="mt-1 text-xs font-semibold text-slate-500">{{ Str::headline($invoice->paymentState()) }}</p></td>
                        <td>@if($isOverdue)<span class="font-bold text-red-700">{{ $invoice->due_on->format('M j, Y') }}</span><span class="mt-1 block text-xs font-bold text-red-700">Overdue</span>@elseif($invoice->due_on){{ $invoice->due_on->format('M j, Y') }}@elseif($invoice->payment_terms==='due_on_receipt')Upon receipt @else&mdash;@endif</td>
                        <td class="text-right font-semibold text-slate-950">${{ number_format($invoice->total_cents/100,2) }}</td>
                        <td class="text-right font-bold text-slate-950">${{ number_format($balance/100,2) }}</td>
                    </tr>
                @endforeach
                @if($readyHandoffs->isEmpty() && $invoices->isEmpty())
                    <tr><td colspan="8" class="py-10 text-center"><p class="font-bold text-slate-900">No billing activity found</p><p class="mt-1 text-sm text-slate-500">Clear filters or create a direct invoice.</p></td></tr>
                @endif
            </tbody>
        </table>
    </div>

    <div class="office-mobile-list" data-office-mobile-list>
        @foreach($readyHandoffs as $handoff)
            <article class="office-mobile-card" data-ready-handoff-card>
                <div class="flex items-start justify-between gap-3"><div><p class="font-bold text-brand-blue">{{ $handoff->serviceTicket->ticket_number }}</p><p class="mt-1 font-semibold text-slate-950">{{ $handoff->serviceTicket->customer->display_name }}</p></div><span class="status-priority">Ready to Invoice</span></div>
                <p class="mt-2 text-sm font-semibold text-slate-700">{{ $handoff->serviceTicket->title }} &middot; {{ $handoff->serviceTicket->serviceLocation->name }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ $handoff->approved_time_minutes }} approved minutes &middot; approved work ready for billing</p>
                @if($activeMembership->hasCapability('invoices.manage'))<form method="POST" action="{{ route('office.billing-handoffs.invoice.store',$handoff) }}" class="mt-4">@csrf<input type="hidden" name="creation_token" value="{{ Str::uuid() }}"><button class="button-primary w-full">Create invoice</button></form>@endif
            </article>
        @endforeach
        @foreach($invoices as $invoice)
            @php($balance=max(0,$invoice->balanceCents()))
            <a class="office-mobile-card" href="{{ route('office.invoices.show',$invoice) }}">
                <div class="flex items-start justify-between gap-3"><div><p class="font-bold text-brand-blue">{{ $invoice->invoice_number }}</p><p class="mt-1 font-semibold text-slate-950">{{ $invoice->customer->display_name }}</p></div><span class="{{ $invoice->status==='issued' ? 'status-active' : ($invoice->status==='void' ? 'status-inactive' : ($invoice->status==='ready_for_review' ? 'status-priority' : 'status-hold')) }}">{{ Str::headline($invoice->status) }}</span></div>
                <p class="mt-2 text-sm font-semibold text-slate-700">@if($invoice->serviceTicket){{ $invoice->serviceTicket->ticket_number }} &middot; {{ $invoice->serviceTicket->title }}@else Direct invoice &middot; {{ $invoice->serviceLocation->name }} @endif</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="font-semibold text-slate-500">Date</dt><dd class="mt-0.5"><x-local-time :value="$invoice->issued_at ?? $invoice->created_at" :timezone="$activeOrganization->timezone" format="M j, Y" /></dd></div><div><dt class="font-semibold text-slate-500">Payment</dt><dd class="mt-0.5">{{ Str::headline($invoice->paymentState()) }}</dd></div><div><dt class="font-semibold text-slate-500">Total</dt><dd class="mt-0.5 font-bold">${{ number_format($invoice->total_cents/100,2) }}</dd></div><div><dt class="font-semibold text-slate-500">Balance</dt><dd class="mt-0.5 font-bold">${{ number_format($balance/100,2) }}</dd></div></dl>
            </a>
        @endforeach
        @if($readyHandoffs->isEmpty() && $invoices->isEmpty())
            <div class="surface p-8 text-center"><p class="font-bold text-slate-900">No billing activity found</p><p class="mt-1 text-sm text-slate-500">Clear filters or create a direct invoice.</p></div>
        @endif
    </div>
    <div class="mt-5">{{ $invoices->links() }}</div>
</x-layouts.office>
