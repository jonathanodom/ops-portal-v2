<x-layouts.office title="Schedule Visit" width="form">
    <x-office.record-header :title="'Schedule '.$visit->displayNumber()" :back-href="route('office.service-tickets.show', $visit->serviceTicket)" :back-label="$visit->serviceTicket->ticket_number" :description="$visit->serviceTicket->title" />
    <x-form-errors />
    <form method="POST" action="{{ route('office.visits.update', $visit) }}" class="office-form-shell">
        @csrf @method('PUT') <div class="p-4">@include('office.visits._form')</div>
        <x-office.form-actions message="Conflict warnings are rechecked when the schedule is saved."><a class="button-secondary" href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}">Cancel</a><button class="button-primary">Save schedule</button></x-office.form-actions>
    </form>
</x-layouts.office>
