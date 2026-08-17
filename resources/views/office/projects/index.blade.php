<x-layouts.office title="Projects" width="workspace">
    <x-office.page-header title="Projects / Engagements" description="Coordinate finite projects and ongoing customer support work.">
        @if($activeMembership->hasCapability('projects.manage'))
            <x-slot:actions>
                <a href="{{ route('office.projects.create') }}" class="button-primary">New Project</a>
            </x-slot:actions>
        @endif
    </x-office.page-header>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Project attention summary">
        @foreach(['active'=>'Active Projects','due_today'=>'Tasks Due Today','overdue'=>'Overdue Tasks','blocked'=>'Blocked Tasks','milestones'=>'Upcoming Milestones'] as $key=>$label)
            <div class="surface p-4"><p class="text-sm font-semibold text-slate-600">{{ $label }}</p><p class="mt-2 text-2xl font-bold {{ in_array($key, ['overdue','blocked']) && $attention[$key] ? 'text-red-700' : 'text-slate-950' }}">{{ $attention[$key] }}</p></div>
        @endforeach
    </section>

    <form method="GET" class="office-filter-toolbar lg:grid-cols-3 2xl:grid-cols-[minmax(200px,1fr)_180px_150px_140px_170px_minmax(220px,auto)]" aria-label="Project filters">
        <div><label for="search" class="form-label">Search</label><input id="search" name="search" class="form-input" value="{{ request('search') }}" placeholder="Project name or number"></div>
        <div><label for="customer_id" class="form-label">Customer</label><select id="customer_id" name="customer_id" class="form-input"><option value="">All customers</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)request('customer_id')===(string)$customer->id)>{{ $customer->displayName }}</option>@endforeach</select></div>
        <div><label for="type" class="form-label">Type</label><select id="type" name="type" class="form-input"><option value="">All types</option>@foreach(['installation_project','ongoing_support','consulting_engineering','internal'] as $value)<option value="{{ $value }}" @selected(request('type')===$value)>{{ Str::headline($value) }}</option>@endforeach</select></div>
        <div><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-input"><option value="">All statuses</option>@foreach(['planning','active','on_hold','completed','canceled'] as $value)<option value="{{ $value }}" @selected(request('status')===$value)>{{ Str::headline($value) }}</option>@endforeach</select></div>
        <div><label for="owner_user_id" class="form-label">Owner</label><select id="owner_user_id" name="owner_user_id" class="form-input"><option value="">All owners</option>@foreach($members as $membership)<option value="{{ $membership->user_id }}" @selected((string)request('owner_user_id')===(string)$membership->user_id)>{{ $membership->user->name }}</option>@endforeach</select></div>
        <div class="flex flex-wrap items-center gap-2"><label class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold"><input type="checkbox" name="has_overdue_tasks" value="1" @checked(request()->boolean('has_overdue_tasks'))> Overdue only</label><button class="button-secondary">Filter</button><a href="{{ route('office.projects.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-brand-blue">Clear</a></div>
    </form>

    <div class="office-table-wrap"><table class="office-data-table"><caption class="sr-only">Projects and engagements</caption><thead><tr><th>Project</th><th>Customer / Location</th><th>Owner</th><th>Tasks</th><th>Status</th><th class="text-right">Action</th></tr></thead><tbody>
        @forelse($projects as $project)<tr>
            <td><a class="font-bold text-slate-950 hover:text-brand-blue" href="{{ route('office.projects.show', $project) }}">{{ $project->name }}</a><p class="mt-1 text-xs text-slate-500">{{ $project->project_number }} · {{ Str::headline($project->type) }}</p></td>
            <td>{{ $project->customer_id ? ($customerMap->get($project->customer_id)?->displayName ?? 'Unavailable') : 'Internal' }}<p class="mt-1 text-xs text-slate-500">{{ $project->service_location_id ? 'Location-specific' : 'Customer-wide / multi-site' }}</p></td>
            <td>{{ $project->owner?->name ?? 'Unassigned' }}</td><td>{{ $project->open_tasks_count }} open @if($project->overdue_tasks_count)<span class="ml-2 font-bold text-red-700">{{ $project->overdue_tasks_count }} overdue</span>@endif</td>
            <td><span class="{{ $project->status === 'active' ? 'status-active' : (in_array($project->status,['completed','canceled']) ? 'status-inactive' : 'status-hold') }}">{{ Str::headline($project->status) }}</span></td>
            <td class="text-right"><a class="inline-flex min-h-11 items-center font-bold text-brand-blue" href="{{ route('office.projects.show', $project) }}">Open<span class="sr-only"> {{ $project->name }}</span></a></td>
        </tr>@empty<tr><td colspan="6" class="py-10 text-center"><p class="font-bold">No Projects found</p><p class="mt-1 text-sm text-slate-500">Clear the filters or create the first Project.</p></td></tr>@endforelse
    </tbody></table></div>
    <div class="office-mobile-list">@forelse($projects as $project)<a href="{{ route('office.projects.show', $project) }}" class="office-mobile-card"><div class="flex items-start justify-between gap-3"><div><p class="font-bold text-slate-950">{{ $project->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $project->project_number }}</p></div><span class="{{ $project->status === 'active' ? 'status-active' : (in_array($project->status,['completed','canceled']) ? 'status-inactive' : 'status-hold') }}">{{ Str::headline($project->status) }}</span></div><p class="mt-3 text-sm text-slate-700">{{ $project->customer_id ? ($customerMap->get($project->customer_id)?->displayName ?? 'Unavailable') : 'Internal Project' }}</p><div class="mt-3 flex justify-between text-sm"><span>{{ $project->open_tasks_count }} open Tasks</span> @if($project->overdue_tasks_count)<strong class="text-red-700">{{ $project->overdue_tasks_count }} overdue</strong>@endif</div></a>@empty<div class="surface p-8 text-center">No Projects found.</div>@endforelse</div>
    <div class="mt-5">{{ $projects->links() }}</div>
</x-layouts.office>
