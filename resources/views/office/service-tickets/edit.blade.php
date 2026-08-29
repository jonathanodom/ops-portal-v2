<x-layouts.office title="Edit Service Ticket" width="form">
    <x-office.record-header title="Edit service ticket" :back-href="route('office.service-tickets.show', $ticket)" :back-label="$ticket->ticket_number" :description="$ticket->title" />
    <x-form-errors />
    <form method="POST" action="{{ route('office.service-tickets.update', $ticket) }}" class="office-form-shell">
        @csrf @method('PUT') <div class="p-4">@include('office.service-tickets._fields')</div>
        <x-office.form-actions><a class="button-secondary" href="{{ route('office.service-tickets.show', $ticket) }}">Cancel</a><button class="button-primary">Save changes</button></x-office.form-actions>
    </form>
</x-layouts.office>
