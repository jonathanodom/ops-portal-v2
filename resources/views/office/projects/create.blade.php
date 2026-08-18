<x-layouts.office title="New Project" width="form">
    <a href="{{ route('office.projects.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Projects</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">New Project / Engagement</h1>
    <p class="mt-2 text-slate-600">Create finite project work or an indefinite support engagement.</p>
    <form method="GET" action="{{ route('office.projects.create') }}" class="surface mt-6 flex flex-wrap items-end gap-3 p-4" aria-label="Load customer context">
        <div class="min-w-64 flex-1"><label class="form-label" for="context_customer_id">Customer context</label><select id="context_customer_id" name="customer_id" class="form-input"><option value="">Internal Project</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)$customerId === (string)$customer->id)>{{ $customer->displayName }}</option>@endforeach</select></div>
        <button class="button-secondary">Load locations and contacts</button>
    </form>
    <form method="POST" action="{{ route('office.projects.store') }}" class="surface mt-4 p-5 sm:p-6">
        @csrf
        <x-form-errors />
        @include('office.projects._project_fields', ['project' => null])
        <div class="mt-6 flex flex-wrap gap-3"><button class="button-primary">Create Project</button><a href="{{ route('office.projects.index') }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
