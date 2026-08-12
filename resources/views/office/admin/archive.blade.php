<x-layouts.office title="Admin Archive">
    @if(session('status'))
        <div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>
    @endif
    <x-form-errors />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-bold uppercase tracking-[.12em] text-brand-blue">Super Admin</p>
            <h1 class="mt-1 text-3xl font-bold">Admin Archive</h1>
            <p class="mt-2 max-w-3xl text-slate-600">Archived visits are excluded from dispatch, field queues, workload counts, and Service Ticket completion checks.</p>
        </div>
    </div>

    <form method="GET" class="surface mt-6 grid gap-4 p-5 md:grid-cols-4">
        <div class="md:col-span-2">
            <label class="form-label" for="search">Visit, ticket, or customer</label>
            <input class="form-input" id="search" name="search" value="{{ request('search') }}" placeholder="Visit number, ticket number, title, or customer">
        </div>
        <div>
            <label class="form-label" for="status">Original status</label>
            <select class="form-input" id="status" name="status">
                <option value="">All statuses</option>
                @foreach(['planned', 'scheduled', 'assigned', 'canceled'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end"><button class="button-primary w-full">Filter archive</button></div>
        <div>
            <label class="form-label" for="archived_from">Archived from</label>
            <input class="form-input" id="archived_from" type="date" name="archived_from" value="{{ request('archived_from') }}">
        </div>
        <div>
            <label class="form-label" for="archived_to">Archived through</label>
            <input class="form-input" id="archived_to" type="date" name="archived_to" value="{{ request('archived_to') }}">
        </div>
        <div class="flex items-end"><a class="button-secondary w-full" href="{{ route('office.admin.archive.index') }}">Clear filters</a></div>
    </form>

    <div class="mt-6 space-y-4">
        @forelse($visits as $visit)
            <article class="surface p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="font-bold text-brand-blue">{{ $visit->serviceTicket->ticket_number }} · {{ $visit->displayLabel() }}</p>
                        <h2 class="mt-1 text-lg font-bold">{{ $visit->serviceTicket->title }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $visit->serviceTicket->customer->display_name }} · {{ $visit->serviceLocation->name }}</p>
                        <p class="mt-2 text-sm"><span class="font-semibold">Original status:</span> {{ ucfirst(str_replace('_', ' ', $visit->status)) }}</p>
                        <p class="mt-1 text-sm"><span class="font-semibold">Archived:</span> <x-local-time :value="$visit->deleted_at" :timezone="$activeOrganization->timezone" /> by {{ $visit->archivedBy?->name ?? 'Former user' }}</p>
                    </div>
                    <a class="button-secondary" href="{{ route('office.service-tickets.show', $visit->serviceTicket) }}">Open Service Ticket</a>
                </div>
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Archive reason</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $visit->archive_reason }}</p>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <form method="POST" action="{{ route('office.admin.archive.visits.restore', $visit->id) }}" class="rounded-lg border border-slate-200 p-4">
                        @csrf
                        <p class="text-sm text-slate-600">Restore this visit to its Service Ticket and operational workflows.</p>
                        <button class="button-primary mt-3 w-full">Restore visit</button>
                    </form>
                    @if(in_array($visit->id, $purgeableVisitIds, true))
                        <form method="POST" action="{{ route('office.admin.archive.visits.destroy', $visit->id) }}" class="rounded-lg border border-red-300 bg-red-50 p-4">
                            @csrf @method('DELETE')
                            <label class="form-label" for="confirm_visit_id_{{ $visit->id }}">Enter {{ $visit->id }} to permanently delete this visit</label>
                            <input class="form-input" id="confirm_visit_id_{{ $visit->id }}" name="confirm_visit_id" inputmode="numeric" required>
                            <p class="mt-2 text-xs font-semibold text-red-800">Permanent deletion cannot be undone.</p>
                            <button class="mt-3 inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-red-700 px-5 text-sm font-bold text-white hover:bg-red-800">Permanently delete</button>
                        </form>
                    @else
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                            <p class="font-bold text-slate-800">Permanent deletion unavailable</p>
                            <p class="mt-1">This visit has execution history, evidence, or linked records that must remain preserved.</p>
                        </div>
                    @endif
                </div>
            </article>
        @empty
            <div class="surface p-8 text-center">
                <h2 class="font-bold">No archived visits</h2>
                <p class="mt-2 text-sm text-slate-500">Eligible visits moved out of active workflows will appear here.</p>
            </div>
        @endforelse
    </div>
    <div class="mt-6">{{ $visits->links() }}</div>
</x-layouts.office>
