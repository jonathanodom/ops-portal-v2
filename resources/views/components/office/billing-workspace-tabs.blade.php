<nav class="office-workspace-tabs" aria-label="Billing workspace">
    @if($activeMembership->hasCapability('billing_handoffs.view'))
        <a href="{{ route('office.billing-handoffs.index') }}" @if(request()->routeIs('office.billing-handoffs.*')) aria-current="page" @endif class="office-workspace-tab {{ request()->routeIs('office.billing-handoffs.*') ? 'office-workspace-tab-active' : '' }}">Queue</a>
    @endif
    @if($activeMembership->hasCapability('invoices.view'))
        <a href="{{ route('office.invoices.index') }}" @if(request()->routeIs('office.invoices.*')) aria-current="page" @endif class="office-workspace-tab {{ request()->routeIs('office.invoices.*') ? 'office-workspace-tab-active' : '' }}">Invoices</a>
    @endif
</nav>
