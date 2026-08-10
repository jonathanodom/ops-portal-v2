<nav class="office-workspace-tabs" aria-label="Customer workspace">
    <a href="{{ route('office.customers.index') }}" @if(request()->routeIs('office.customers.*')) aria-current="page" @endif class="office-workspace-tab {{ request()->routeIs('office.customers.*') ? 'office-workspace-tab-active' : '' }}">Customers</a>
    <a href="{{ route('office.locations.index') }}" @if(request()->routeIs('office.locations.*')) aria-current="page" @endif class="office-workspace-tab {{ request()->routeIs('office.locations.*') ? 'office-workspace-tab-active' : '' }}">Locations</a>
</nav>
