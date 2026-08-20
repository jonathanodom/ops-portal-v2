<x-layouts.office :title="'Purge '.$ticket->ticket_number" width="form">
    <nav class="mb-5 text-sm font-semibold text-slate-600" aria-label="Breadcrumb">
        <a class="text-brand-blue underline" href="{{ route('office.service-tickets.show', $ticket) }}">{{ $ticket->ticket_number }}</a>
        <span aria-hidden="true"> / </span><span>Field-test purge</span>
    </nav>

    <section class="rounded-xl border-2 border-red-400 bg-white">
        <header class="border-b border-red-200 bg-red-50 p-5 sm:p-6">
            <p class="text-sm font-bold uppercase tracking-wide text-red-700">Field Testing Tools</p>
            <h1 class="mt-1 text-2xl font-bold text-red-950">Permanently purge test Service Ticket</h1>
            <p class="mt-3 text-sm text-red-900">This is not an archive, cancellation, void, or refund. It permanently removes this Service Ticket and its owned Ops Portal test records and cannot be undone.</p>
        </header>
        <div class="space-y-6 p-5 sm:p-6">
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="font-bold">{{ $ticket->ticket_number }} · {{ $ticket->title }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $ticket->customer->display_name }} · {{ $ticket->serviceLocation->name }}</p>
            </div>

            @if($preview['blockers']['external_invoice_ids'])
                <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-950" role="alert">
                    <p class="font-bold">Purge blocked</p>
                    <p class="mt-1 text-sm">An Invoice outside this Ticket aggregate references its operational records. Remove or correct that relationship before purging.</p>
                </div>
            @endif

            <div>
                <h2 class="font-bold text-slate-950">Current dependency inventory</h2>
                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                    @foreach($preview['counts'] as $label => $count)
                        <div class="flex min-h-11 items-center justify-between rounded-lg border border-slate-200 px-3"><dt>{{ ucfirst(str_replace('_', ' ', $label)) }}</dt><dd class="font-bold">{{ number_format($count) }}</dd></div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                <p class="font-bold">External payments are not reversed</p>
                <p class="mt-1">If a payment was processed through Square, Stripe, or another provider, purging this Ops Portal test record does not refund, void, or reverse the provider transaction.</p>
            </div>

            <form method="POST" action="{{ route('office.service-tickets.field-test-purge.destroy', $ticket) }}" class="space-y-5">
                @csrf
                <div>
                    <label class="form-label" for="ticket_number">Enter {{ $ticket->ticket_number }} to confirm</label>
                    <input class="form-input @error('ticket_number') border-red-500 bg-red-50 @enderror" id="ticket_number" name="ticket_number" autocomplete="off" required @error('ticket_number') aria-invalid="true" aria-describedby="ticket_number-error" @enderror>
                    <x-field-error field="ticket_number" />
                </div>
                <label class="flex min-h-11 items-start gap-3 rounded-lg border border-red-300 bg-red-50 p-3 text-red-950">
                    <input class="mt-1" type="checkbox" name="acknowledge" value="1" required>
                    <span class="font-semibold">I understand this permanently destroys the listed test data and cannot be undone.</span>
                </label>
                <x-field-error field="acknowledge" />
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <a class="button-secondary" href="{{ route('office.service-tickets.show', $ticket) }}">Cancel</a>
                    <button class="inline-flex min-h-11 items-center justify-center rounded-lg bg-red-700 px-4 py-2 font-bold text-white hover:bg-red-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-50" @disabled($preview['blockers']['external_invoice_ids'])>Permanently purge test data</button>
                </div>
            </form>
        </div>
    </section>
</x-layouts.office>
