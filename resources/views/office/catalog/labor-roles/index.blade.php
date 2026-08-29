<x-layouts.office title="Labor cost defaults" width="detail">
    <x-office.primary-toolbar title="Labor cost defaults" description="Approved estimating defaults for Catalog Services. These values are not payroll or compensation records." eyebrow="Catalog" />
    <x-office.catalog-tabs />
    <x-form-errors />
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
        <section class="surface overflow-hidden" aria-labelledby="labor-role-list">
            <header class="border-b border-slate-200 p-5"><h2 id="labor-role-list" class="text-lg font-bold">Approved roles</h2></header>
            <div class="divide-y divide-slate-200">
                @forelse($roles as $role)
                    <form method="POST" action="{{ route('office.catalog.labor-roles.update',$role) }}" class="grid gap-4 p-5 md:grid-cols-[160px_minmax(0,1fr)_180px_auto]" data-offline-write>
                        @csrf @method('PUT')
                        <div><label class="form-label" for="role-code-{{ $role->id }}">Code</label><input class="form-input" id="role-code-{{ $role->id }}" name="code" value="{{ $role->code }}" required></div>
                        <div><label class="form-label" for="role-name-{{ $role->id }}">Name</label><input class="form-input" id="role-name-{{ $role->id }}" name="name" value="{{ $role->name }}" required></div>
                        <div><label class="form-label" for="role-cost-{{ $role->id }}">Hourly internal cost</label><input class="form-input" id="role-cost-{{ $role->id }}" name="hourly_cost" inputmode="decimal" value="{{ number_format($role->hourly_cost_cents/100,2,'.','') }}" required></div>
                        <div class="flex flex-wrap items-end gap-3"><label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="active" value="1" @checked($role->active)> Active</label><button class="button-secondary">Save</button></div>
                    </form>
                @empty<p class="p-6 text-sm text-slate-600">No labor cost defaults have been created.</p>@endforelse
            </div>
        </section>
        <section class="surface p-5" aria-labelledby="add-labor-role"><h2 id="add-labor-role" class="text-lg font-bold">Add labor role</h2><p class="mt-1 text-sm text-slate-600">Use a functional estimating role, not an employee name.</p>
            <form method="POST" action="{{ route('office.catalog.labor-roles.store') }}" class="mt-5 space-y-4" data-offline-write>@csrf
                <div><label class="form-label" for="labor-role-code">Code</label><input class="form-input" id="labor-role-code" name="code" value="{{ old('code') }}" required></div>
                <div><label class="form-label" for="labor-role-name">Name</label><input class="form-input" id="labor-role-name" name="name" value="{{ old('name') }}" required></div>
                <div><label class="form-label" for="labor-role-cost">Hourly internal cost</label><input class="form-input" id="labor-role-cost" name="hourly_cost" inputmode="decimal" value="{{ old('hourly_cost') }}" required></div>
                <label class="flex min-h-11 items-center gap-2"><input type="checkbox" name="active" value="1" checked> Active</label><button class="button-primary w-full">Add role</button>
            </form>
        </section>
    </div>
</x-layouts.office>
