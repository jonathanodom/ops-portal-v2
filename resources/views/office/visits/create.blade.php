<x-layouts.office title="Add Visit" width="form">
    <x-office.record-header title="Add Visit" :back-href="route('office.service-tickets.show', $ticket)" :back-label="$ticket->ticket_number" :description="$ticket->title" />
    <x-form-errors />
    <form method="POST" action="{{ route('office.service-tickets.visits.store', $ticket) }}" class="office-form-shell">
        @csrf <div class="p-4">@include('office.visits._form')</div>
        <x-office.form-actions message="Scheduling uses the Service Location timezone."><a class="button-secondary" href="{{ route('office.service-tickets.show', $ticket) }}">Cancel</a><button class="button-primary">Create Visit</button></x-office.form-actions>
    </form>
</x-layouts.office>
