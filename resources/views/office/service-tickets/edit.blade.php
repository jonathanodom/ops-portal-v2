<x-layouts.office title="Edit Service Ticket">
    <a href="{{ route('office.service-tickets.show', $ticket) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← {{ $ticket->ticket_number }}</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">Edit service ticket</h1>
    <form method="POST" action="{{ route('office.service-tickets.update', $ticket) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf @method('PUT') <x-form-errors />
        @include('office.service-tickets._fields')
        <div class="mt-6 flex gap-3"><button class="button-primary">Save changes</button><a class="button-secondary" href="{{ route('office.service-tickets.show', $ticket) }}">Cancel</a></div>
    </form>
</x-layouts.office>
