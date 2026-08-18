<x-layouts.office title="New Service Ticket">
    <a href="{{ route('office.service-tickets.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Service Tickets</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">New service ticket</h1>
    <form method="POST" action="{{ route('office.service-tickets.store') }}" class="surface mt-6 p-5 sm:p-6">
        @csrf
        <x-form-errors />
        @include('office.service-tickets._fields')
        @include('office.service-tickets._initial-visit-fields')
        <div class="mt-6 flex flex-wrap gap-3"><button class="button-primary">Create service ticket</button><a href="{{ route('office.service-tickets.index') }}" class="button-secondary">Cancel</a></div>
    </form>
    @if($canQuickAddCustomer)
        @include('office.service-tickets._quick-customer-dialog')
    @endif
</x-layouts.office>
