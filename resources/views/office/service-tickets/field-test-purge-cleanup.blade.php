<x-layouts.office title="Purge cleanup" width="form">
    <section class="rounded-xl border-2 border-red-400 bg-white p-5 sm:p-6">
        @if(session('error'))<div class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4 font-semibold text-red-950" role="alert">{{ session('error') }}</div>@endif
        <p class="text-sm font-bold uppercase tracking-wide text-red-700">Field Testing Tools</p>
        <h1 class="mt-1 text-2xl font-bold">Private storage cleanup</h1>
        <p class="mt-3 text-slate-700">The relational Ticket purge has already committed. This page contains no deleted Ticket identity or private storage paths.</p>
        <dl class="mt-5 grid gap-2 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 p-3"><dt class="text-sm text-slate-500">Status</dt><dd class="font-bold">{{ ucfirst($cleanup->status) }}</dd></div>
            <div class="rounded-lg border border-slate-200 p-3"><dt class="text-sm text-slate-500">Cleanup attempts</dt><dd class="font-bold">{{ $cleanup->failure_count + 1 }}</dd></div>
            <div class="rounded-lg border border-slate-200 p-3"><dt class="text-sm text-slate-500">Private objects</dt><dd class="font-bold">{{ $cleanup->record_counts['private_objects'] ?? 0 }}</dd></div>
        </dl>
        @if($cleanup->status !== 'completed')
            <form method="POST" action="{{ route('office.field-test-purge-cleanups.retry', $cleanup->public_id) }}" class="mt-6">@csrf<button class="button-primary w-full sm:w-auto">Retry private storage cleanup</button></form>
        @else
            <a class="button-primary mt-6" href="{{ route('office.service-tickets.index') }}">Return to Service Tickets</a>
        @endif
    </section>
</x-layouts.office>
