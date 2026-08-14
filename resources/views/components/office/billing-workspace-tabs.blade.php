<nav class="office-workspace-tabs" aria-label="Billing workspace">
    @if($activeMembership->hasCapability('invoices.view'))
        <a href="{{ route('office.invoices.index') }}" @if(request()->routeIs('office.invoices.*')) aria-current="page" @endif class="office-workspace-tab {{ request()->routeIs('office.invoices.*') ? 'office-workspace-tab-active' : '' }}">Billing / Invoices</a>
    @elseif($activeMembership->hasCapability('billing_handoffs.view'))
        <a href="{{ route('office.billing-handoffs.index') }}" aria-current="page" class="office-workspace-tab office-workspace-tab-active">Queue</a>
    @endif
</nav>
