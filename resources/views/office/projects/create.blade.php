<x-layouts.office title="New Project" width="form">
    <x-office.record-header title="New Project / Engagement" :back-href="route('office.projects.index')" back-label="Projects" description="Create finite project work or an indefinite support engagement." />
    <form method="GET" action="{{ route('office.projects.create') }}" class="office-form-shell flex flex-wrap items-end gap-3 p-3" aria-label="Load customer context">
        <div class="min-w-64 flex-1"><label class="form-label" for="context_customer_id">Customer context</label><select id="context_customer_id" name="customer_id" class="form-input"><option value="">Internal Project</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)$customerId === (string)$customer->id)>{{ $customer->displayName }}</option>@endforeach</select></div>
        <button class="button-secondary">Load locations and contacts</button>
    </form>
    <x-form-errors />
    <form method="POST" action="{{ route('office.projects.store') }}" class="office-form-shell">
        @csrf <div class="p-4">@include('office.projects._project_fields', ['project' => null])</div>
        <x-office.form-actions><a href="{{ route('office.projects.index') }}" class="button-secondary">Cancel</a><button class="button-primary">Create Project</button></x-office.form-actions>
    </form>
</x-layouts.office>
