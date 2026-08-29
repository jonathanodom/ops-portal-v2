<x-layouts.office title="New Service Ticket" width="form">
    <x-office.record-header title="New service ticket" :back-href="route('office.service-tickets.index')" back-label="Service Tickets" description="Create the service request and optionally schedule its first Visit." />
    <x-form-errors />
    <form method="POST" action="{{ route('office.service-tickets.store') }}" class="office-form-shell">
        @csrf
        <div class="p-4">@include('office.service-tickets._fields')</div>
        <div class="border-t border-slate-200 p-4">@include('office.service-tickets._initial-visit-fields')</div>
        <x-office.form-actions message="Customer, location, and operational scope are required before dispatch."><a href="{{ route('office.service-tickets.index') }}" class="button-secondary">Cancel</a><button class="button-primary">Create service ticket</button></x-office.form-actions>
    </form>
    @if($canQuickAddCustomer) @include('office.service-tickets._quick-customer-dialog') @endif
</x-layouts.office>
