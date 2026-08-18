<x-layouts.office title="Create Service Ticket" width="form">
    <a href="{{ route('office.projects.show', $project) }}#tickets" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← {{ $project->project_number }}</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">Create Service Ticket</h1>
    <div class="mt-4 rounded-lg border border-brand-blue bg-blue-50 p-4">
        <p class="text-sm font-semibold uppercase tracking-wide text-brand-blue">Project context</p>
        <p class="mt-1 font-bold text-slate-950">{{ $project->project_number }} — {{ $project->name }}</p>
        <p class="mt-1 text-sm text-slate-700">The new canonical Service Ticket will be linked to this Project after creation.</p>
    </div>
    <form method="POST" action="{{ route('office.projects.service-tickets.store', $project) }}" class="surface mt-6 p-5 sm:p-6">
        @csrf
        <x-form-errors />
        @include('office.service-tickets._fields', ['projectContext' => true])
        @include('office.service-tickets._initial-visit-fields')
        <div class="mt-6 flex flex-wrap gap-3"><button class="button-primary">Create and link Service Ticket</button><a href="{{ route('office.projects.show', $project) }}#tickets" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
