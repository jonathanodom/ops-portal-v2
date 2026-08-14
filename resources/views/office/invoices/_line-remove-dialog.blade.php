@php
    $removeContext = (string) old('line_remove_context');
    $isCurrentRemoval = $removeContext === (string) $line->id;
    $hasOperationalSource = collect([
        $line->source_visit_id,
        $line->source_closeout_id,
        $line->source_review_id,
        $line->source_time_entry_id,
        $line->source_travel_seconds,
        $line->source_part_proposal_id,
    ])->contains(fn ($value) => $value !== null);
@endphp
<dialog
    id="invoice-line-remove-{{ $line->id }}"
    class="invoice-line-dialog"
    data-invoice-item-dialog
    data-auto-open="{{ $isCurrentRemoval && $errors->any() ? 'true' : 'false' }}"
    aria-labelledby="invoice-line-remove-title-{{ $line->id }}"
>
    <form method="POST" action="{{ route('office.invoices.lines.destroy', [$invoice, $line]) }}" class="invoice-line-dialog-panel">
        @csrf @method('DELETE')
        <input type="hidden" name="line_remove_context" value="{{ $line->id }}">
        <header class="invoice-line-dialog-header">
            <div class="min-w-0">
                <p class="text-sm font-bold text-red-700">Destructive invoice edit</p>
                <h2 id="invoice-line-remove-title-{{ $line->id }}" class="mt-1 text-xl font-bold text-slate-950">Remove invoice line?</h2>
                <p class="mt-1 truncate text-sm text-slate-600">{{ $line->description }}</p>
            </div>
            <button type="button" class="button-secondary min-w-11 px-3" data-invoice-item-close aria-label="Close line removal">Close</button>
        </header>
        <div class="invoice-line-dialog-body">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-950">
                <p class="font-bold">This removes the charge from {{ $invoice->invoice_number }} and immediately recalculates its totals.</p>
                <p class="mt-1">The source Visit, Closeout, Review, time, proposal, and Catalog records are never deleted.</p>
            </div>
            <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                <div><dt class="font-semibold text-slate-500">Line type</dt><dd class="mt-1 font-bold text-slate-900">{{ ucfirst(str_replace('_', ' ', $line->line_type)) }}</dd></div>
                <div><dt class="font-semibold text-slate-500">Current amount</dt><dd class="mt-1 font-bold text-slate-900">${{ number_format($line->total_cents / 100, 2) }}</dd></div>
            </dl>
            <div class="mt-5">
                <label class="form-label" for="remove-reason-{{ $line->id }}">Removal reason{{ $hasOperationalSource ? ' (required)' : ' (optional)' }}</label>
                <textarea class="form-textarea" id="remove-reason-{{ $line->id }}" name="reason" maxlength="2000" @if($hasOperationalSource) required @endif @if($isCurrentRemoval) @error('reason') aria-invalid="true" aria-describedby="remove-reason-{{ $line->id }}-error" autofocus @enderror @endif>{{ $isCurrentRemoval ? old('reason') : '' }}</textarea>
                @if($isCurrentRemoval) @error('reason')<p id="remove-reason-{{ $line->id }}-error" class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                @if($hasOperationalSource)<p class="mt-2 text-sm text-slate-600">Required because this line represents approved operational work.</p>@endif
            </div>
        </div>
        <footer class="invoice-line-dialog-footer">
            <button type="button" class="button-secondary" data-invoice-item-close>Cancel</button>
            <button class="button-danger">Remove line</button>
        </footer>
    </form>
</dialog>
