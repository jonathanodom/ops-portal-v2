@php
    $canCorrectSubmittedTime = $canCorrectSubmittedTime ?? false;
    $submittedCorrectionEligible = $canCorrectSubmittedTime
        && $entry->effective_ended_at
        && ! $entry->active_user_id
        && $entry->closeout?->status === 'submitted'
        && ! in_array($visit->status, ['approved', 'canceled'], true)
        && ! in_array($visit->serviceTicket->status, ['completed', 'canceled'], true)
        && ! $visit->serviceTicket->billing_handoff_exists;
    $submittedCorrectionForm = 'submitted-'.$context.'-'.$entry->id;
@endphp

@if($entry->hasSubmittedCorrection())
    <div class="mt-2 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm">
        <p class="font-bold text-blue-950">Corrected submitted time</p>
        <p class="mt-1 text-blue-900">Originally <x-local-time :value="$entry->started_at" :timezone="$visit->timezone" format="M j, g:i A T" />–<x-local-time :value="$entry->ended_at" :timezone="$visit->timezone" format="M j, g:i A T" /></p>
        <details class="mt-2">
            <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correction history</summary>
            <ol class="space-y-3 border-t border-blue-200 pt-3">
                @foreach($entry->corrections as $history)
                    <li>
                        <p class="font-semibold">Correction {{ $history->sequence }} · {{ $history->correctedBy?->name ?? 'Former user' }}</p>
                        <p class="mt-1 text-slate-700"><x-local-time :value="$history->previous_started_at" :timezone="$visit->timezone" format="M j, g:i A T" />–<x-local-time :value="$history->previous_ended_at" :timezone="$visit->timezone" format="M j, g:i A T" /> → <x-local-time :value="$history->corrected_started_at" :timezone="$visit->timezone" format="M j, g:i A T" />–<x-local-time :value="$history->corrected_ended_at" :timezone="$visit->timezone" format="M j, g:i A T" /></p>
                        <p class="mt-1 whitespace-pre-line text-slate-700">{{ $history->reason }}</p>
                        <p class="mt-1 text-xs text-slate-500"><x-local-time :value="$history->created_at" :timezone="$activeOrganization->timezone" format="M j, Y g:i A T" /></p>
                    </li>
                @endforeach
            </ol>
        </details>
    </div>
@endif

@if($submittedCorrectionEligible)
    <details class="mt-2" @if(old('submitted_time_entry_id') == $entry->id) open @endif>
        <summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Correct submitted time</summary>
        <div class="mb-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
            Submitted time correction changes the factual recorded interval. Review adjustment separately controls approved or billable minutes.
        </div>
        <form method="POST" action="{{ route('office.visit-time-entries.submitted-correction.update', $entry) }}" class="space-y-3">
            @csrf
            @method('PUT')
            <input type="hidden" name="context" value="{{ $context }}">
            <input type="hidden" name="submitted_time_entry_id" value="{{ $entry->id }}">
            @if($context === 'review')
                <input type="hidden" name="review_closeout_id" value="{{ $reviewCloseoutId }}">
            @endif
            <label class="form-label" for="submitted_started_{{ $context }}_{{ $entry->id }}">Start · {{ $visit->timezone }}</label>
            <input class="form-input" id="submitted_started_{{ $context }}_{{ $entry->id }}" name="started_at" type="datetime-local" value="{{ old('submitted_time_entry_id') == $entry->id ? old('started_at') : $entry->effective_started_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
            <label class="form-label" for="submitted_ended_{{ $context }}_{{ $entry->id }}">End · {{ $visit->timezone }}</label>
            <input class="form-input" id="submitted_ended_{{ $context }}_{{ $entry->id }}" name="ended_at" type="datetime-local" value="{{ old('submitted_time_entry_id') == $entry->id ? old('ended_at') : $entry->effective_ended_at->timezone($visit->timezone)->format('Y-m-d\TH:i') }}" required>
            <label class="form-label" for="submitted_reason_{{ $context }}_{{ $entry->id }}">Why was the recorded clock interval wrong?</label>
            <textarea class="form-textarea" id="submitted_reason_{{ $context }}_{{ $entry->id }}" name="reason" required maxlength="2000">{{ old('submitted_time_entry_id') == $entry->id ? old('reason') : '' }}</textarea>
            <button class="button-secondary w-full">Save submitted time correction</button>
        </form>
    </details>
@endif
