<x-layouts.office title="Schedule Visit">
    <a href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← {{ $visit->serviceTicket->ticket_number }}</a><h1 class="mt-2 text-3xl font-bold">Schedule visit #{{ $visit->id }}</h1>
    <form method="POST" action="{{ route('office.visits.update', $visit) }}" class="surface mt-6 p-5 sm:p-6">@csrf @method('PUT') @include('office.visits._form')<div class="mt-6 flex gap-3"><button class="button-primary">Save schedule</button><a class="button-secondary" href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}">Cancel</a></div></form>
</x-layouts.office>
