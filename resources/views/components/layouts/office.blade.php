@props(['title' => 'Office', 'width' => 'default'])
@php
    $contentWidthClass = match ($width) {
        'workspace' => 'w-full max-w-none',
        'detail' => 'mx-auto w-full max-w-[1600px]',
        'form' => 'mx-auto w-full max-w-4xl',
        default => 'mx-auto w-full max-w-7xl',
    };
    $customerWorkspaceActive = request()->routeIs('office.customers.*') || request()->routeIs('office.locations.*');
    $settingsAccess = $activeMembership->hasCapability('organization.settings.manage')
        || $activeMembership->hasCapability('billing.settings.manage')
        || $activeMembership->hasCapability('payments.view')
        || $activeMembership->hasCapability('opportunities.admin')
        || $activeMembership->hasCapability('proposal.templates.manage');
    $officeNavigation = array_values(array_filter([
        ['key' => 'home', 'label' => 'Home', 'mobile_label' => 'Home', 'icon' => 'home', 'href' => route('office.home'), 'active' => request()->routeIs('office.home', 'office.search')],
        $activeMembership->hasCapability('customers.view') ? ['key' => 'customers', 'label' => 'Customers', 'mobile_label' => 'Customers', 'icon' => 'customers', 'href' => route('office.customers.index'), 'active' => $customerWorkspaceActive] : null,
        $activeMembership->hasCapability('projects.view') ? ['key' => 'projects', 'label' => 'Projects', 'mobile_label' => 'Projects', 'icon' => 'projects', 'href' => route('office.projects.index'), 'active' => request()->routeIs('office.projects.*')] : null,
        $activeMembership->hasCapability('opportunities.view') ? ['key' => 'opportunities', 'label' => 'Opportunities', 'mobile_label' => 'Opportunities', 'icon' => 'opportunities', 'href' => route('office.opportunities.index'), 'active' => request()->routeIs('office.opportunities.*')] : null,
        $activeMembership->hasCapability('quotes.approve') ? ['key' => 'approvals', 'label' => 'Quote Approvals', 'mobile_label' => 'Approvals', 'icon' => 'approvals', 'href' => route('office.quote-approvals.index'), 'active' => request()->routeIs('office.quote-approvals.*')] : null,
        $activeMembership->hasCapability('service_tickets.view') ? ['key' => 'tickets', 'label' => 'Service Tickets', 'mobile_label' => 'Tickets', 'icon' => 'tickets', 'href' => route('office.service-tickets.index'), 'active' => request()->routeIs('office.service-tickets.*') || request()->routeIs('office.visits.*')] : null,
        $activeMembership->hasCapability('service_tickets.view') ? ['key' => 'dispatch', 'label' => 'Dispatch', 'mobile_label' => 'Dispatch', 'icon' => 'dispatch', 'href' => route('office.dispatch.index'), 'active' => request()->routeIs('office.dispatch.*')] : null,
        ($activeMembership->hasCapability('catalog.view') || $activeMembership->hasCapability('subscriptions.view')) ? ['key' => 'catalog', 'label' => 'Catalog', 'mobile_label' => 'Catalog', 'icon' => 'catalog', 'href' => $activeMembership->hasCapability('catalog.view') ? route('office.catalog.services.index') : route('office.subscriptions.index'), 'active' => request()->routeIs('office.catalog.*') || request()->routeIs('office.subscriptions.*')] : null,
        $activeMembership->hasCapability('closeouts.inspect') ? ['key' => 'review', 'label' => 'Review', 'mobile_label' => 'Review', 'icon' => 'review', 'href' => route('office.closeout-reviews.index'), 'active' => request()->routeIs('office.closeout-reviews.*')] : null,
        ($activeMembership->hasCapability('billing_handoffs.view') || $activeMembership->hasCapability('invoices.view')) ? ['key' => 'billing', 'label' => 'Billing', 'mobile_label' => 'Billing', 'icon' => 'billing', 'href' => $activeMembership->hasCapability('invoices.view') ? route('office.invoices.index') : route('office.billing-handoffs.index'), 'active' => request()->routeIs('office.billing-handoffs.*') || request()->routeIs('office.invoices.*') || request()->routeIs('office.billing.settings.*')] : null,
        $activeMembership->hasCapability('operations.health.view') ? ['key' => 'health', 'label' => 'Health', 'mobile_label' => 'Health', 'icon' => 'health', 'href' => route('office.operations.health'), 'active' => request()->routeIs('office.operations.*')] : null,
        $activeMembership->hasCapability('visits.archive.manage') ? ['key' => 'archive', 'label' => 'Admin Archive', 'mobile_label' => 'Archive', 'icon' => 'archive', 'href' => route('office.admin.archive.index'), 'active' => request()->routeIs('office.admin.archive.*')] : null,
        $settingsAccess ? ['key' => 'settings', 'label' => 'Settings', 'mobile_label' => 'Settings', 'icon' => 'settings', 'href' => route('office.settings.index'), 'active' => request()->routeIs('office.settings.*')] : null,
    ]));
    $sidebarPreferenceKey = 'ndt:office-sidebar:'.auth()->id().':'.$activeOrganization->id;
@endphp
<!DOCTYPE html>
<html lang="en" data-office-sidebar-state="expanded" data-office-sidebar-key="{{ $sidebarPreferenceKey }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1D80F7">
    <title>{{ $title ?? 'Office' }} | NewDay Tech Ops</title>
    <script>
        (() => {
            const root = document.documentElement;
            try {
                const saved = localStorage.getItem(root.dataset.officeSidebarKey);
                root.dataset.officeSidebarState = ['expanded', 'collapsed'].includes(saved) ? saved : 'expanded';
            } catch (_) {
                root.dataset.officeSidebarState = 'expanded';
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="office-shell bg-canvas">
    <div data-connectivity-banner hidden class="bg-amber-100 px-4 py-2 text-center text-sm font-bold text-amber-900" role="status">
        You’re offline. Changes cannot be saved until connectivity returns.
    </div>
    <div class="min-h-screen lg:grid" data-office-shell-grid>
        <aside id="office-sidebar" class="office-sidebar hidden border-r border-slate-200 bg-white p-4 lg:flex lg:flex-col" data-office-sidebar>
            <div class="office-sidebar-brand">
                <x-organization-logo variant="full" class="office-sidebar-full-logo max-h-16 min-w-0 flex-1 object-contain object-left" />
                <x-organization-logo variant="mark" class="office-sidebar-mark-logo h-10 w-10 object-contain" />
                <button type="button" class="office-sidebar-toggle" aria-controls="office-sidebar" aria-expanded="true" aria-label="Collapse office navigation" title="Collapse office navigation" data-office-sidebar-toggle>
                    <x-office.nav-icon name="panel" />
                </button>
            </div>
            <div class="office-sidebar-section-label mt-7 text-xs font-bold uppercase tracking-[0.14em] text-slate-600">Office workspace</div>
            <nav class="mt-3" aria-label="Office">
                @foreach ($officeNavigation as $item)
                    <a href="{{ $item['href'] }}"
                       data-office-nav-key="{{ $item['key'] }}"
                       data-office-tooltip="{{ $item['label'] }}"
                       aria-label="{{ $item['label'] }}"
                       @if($item['key'] === 'customers') data-office-primary-customers @endif
                       @if($item['active']) aria-current="page" @endif
                       class="office-nav-link mt-1 flex min-h-11 items-center gap-3 rounded-lg border-l-4 px-4 text-sm font-bold {{ $item['active'] ? 'is-active border-brand-blue bg-blue-50 text-brand-blue-dark' : 'border-transparent text-slate-600 hover:bg-slate-50' }}">
                        <x-office.nav-icon :name="$item['icon']" />
                        <span class="office-nav-label">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="office-sidebar-account mt-auto border-t border-slate-200 pt-5">
                <div class="office-account-context flex min-h-11 items-center gap-3" role="group" tabindex="0" aria-label="Signed in as {{ auth()->user()->name }} for {{ $activeOrganization->name }}" data-office-tooltip="{{ auth()->user()->name }} · {{ $activeOrganization->name }}">
                    <x-office.nav-icon name="user" />
                    <div class="office-account-copy min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900">{{ auth()->user()->name }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">{{ $activeOrganization->name }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-4">
                    @csrf
                    <button class="office-sign-out button-secondary w-full gap-2" data-office-tooltip="Sign out" aria-label="Sign out">
                        <x-office.nav-icon name="logout" />
                        <span class="office-nav-label">Sign out</span>
                    </button>
                </form>
            </div>
        </aside>

        <div class="min-w-0">
            <header class="border-b border-slate-200 bg-white px-3 py-2 sm:px-4 lg:px-5">
                <div class="{{ $contentWidthClass }} flex items-center justify-between gap-4" data-office-header-width="{{ $width }}">
                    <div class="flex items-center gap-3">
                        <x-organization-logo variant="mark" class="h-10 w-10 object-contain lg:hidden" />
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-blue">Office</p>
                            <p class="text-sm font-semibold text-slate-600">{{ $activeOrganization->name }}</p>
                        </div>
                    </div>
                    @if ($activeMembership->hasCapability('experience.field.access'))
                        <a href="{{ route('field.home') }}" class="button-secondary">Open field view</a>
                    @endif
                </div>
            </header>
            <nav class="office-mobile-primary-nav flex gap-2 overflow-x-auto border-b border-slate-200 bg-white px-4 py-2 lg:hidden" aria-label="Office mobile">
                @foreach ($officeNavigation as $item)
                    <a href="{{ $item['href'] }}"
                       @if($item['key'] === 'customers') data-office-primary-customers @endif
                       @if($item['active']) aria-current="page" @endif
                       class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-3 text-sm font-bold {{ $item['active'] ? 'bg-blue-50 text-brand-blue-dark' : 'text-slate-600' }}">{{ $item['mobile_label'] }}</a>
                @endforeach
            </nav>
            <main id="main-content" class="{{ $contentWidthClass }} p-2 sm:p-3 lg:p-4" data-office-width="{{ $width }}">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>
