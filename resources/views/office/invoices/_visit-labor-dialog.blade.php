@php
    $eligibleVisitLabor = $visitLaborCandidates['eligible'] ?? collect();
    $billedVisitLabor = $visitLaborCandidates['billed'] ?? collect();
@endphp
<dialog
    id="invoice-visit-labor-dialog"
    class="invoice-line-dialog"
    data-invoice-item-dialog
    data-auto-open="{{ old('visit_labor_context') && $errors->has('visit') ? 'true' : 'false' }}"
    aria-labelledby="invoice-visit-labor-title"
>
    <div class="invoice-line-dialog-panel">
        <header class="invoice-line-dialog-header">
            <div>
                <p class="text-sm font-bold text-brand-blue">Approved operational work</p>
                <h2 id="invoice-visit-labor-title" class="mt-1 text-xl font-bold text-slate-950">Add service labor</h2>
                <p class="mt-1 text-sm text-slate-600">Only approved, unbilled Visits for this customer and location are eligible.</p>
            </div>
            <button type="button" class="button-secondary min-w-11 px-3" data-invoice-item-close aria-label="Close approved Visit labor">Close</button>
        </header>
        <div class="invoice-line-dialog-body">
            @error('visit')<div class="mb-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm font-semibold text-red-800" role="alert">{{ $message }}</div>@enderror
            <section aria-labelledby="eligible-visit-labor-heading">
                <h3 id="eligible-visit-labor-heading" class="font-bold text-slate-950">Eligible approved Visits</h3>
                <div class="mt-3 space-y-3">
                    @forelse($eligibleVisitLabor as $candidate)
                        @php($visit = $candidate['visit'])
                        <article class="rounded-lg border border-slate-200 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="font-bold text-slate-950">{{ $visit->displayLabel() }}@if($visit->scheduledStartLocal()) &middot; {{ $visit->scheduledStartLocal()->format('M j, Y') }}@endif</p>
                                    <p class="mt-1 text-sm text-slate-700">{{ $visit->serviceTicket->title }}</p>
                                    <p class="mt-1 text-sm font-semibold text-emerald-700">{{ $candidate['approvedMinutes'] }} approved labor minutes &middot; Unbilled</p>
                                </div>
                                <form method="POST" action="{{ route('office.invoices.visit-labor.store', [$invoice, $visit]) }}">
                                    @csrf
                                    <input type="hidden" name="visit_labor_context" value="1">
                                    <button class="button-primary">Add labor</button>
                                </form>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">No approved unbilled Visit labor matches this invoice customer and service location.</div>
                    @endforelse
                </div>
            </section>

            @if($billedVisitLabor->isNotEmpty())
                <section class="mt-6 border-t border-slate-200 pt-5" aria-labelledby="represented-visit-labor-heading">
                    <h3 id="represented-visit-labor-heading" class="font-bold text-slate-950">Already represented</h3>
                    <div class="mt-3 space-y-3">
                        @foreach($billedVisitLabor as $candidate)
                            <article class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                                <p class="font-bold text-slate-900">{{ $candidate['visit']->displayLabel() }} &middot; {{ $candidate['visit']->serviceTicket->title }}</p>
                                <p class="mt-1 text-slate-600">Already represented on <a class="font-bold text-brand-blue underline" href="{{ route('office.invoices.show', $candidate['invoice']) }}">{{ $candidate['invoice']->invoice_number }}</a>.</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
        <footer class="invoice-line-dialog-footer"><button type="button" class="button-secondary" data-invoice-item-close>Done</button></footer>
    </div>
</dialog>
