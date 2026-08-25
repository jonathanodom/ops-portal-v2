<section id="field-v2-panel-work" role="tabpanel" aria-labelledby="field-v2-tab-work" tabindex="0" data-v2-panel="work" class="space-y-3">
    <div class="surface p-5">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Primary scope</p><h2 class="text-lg font-bold">{{ $visit->serviceTicket->title }}</h2></div>@if($activeTimer?->category === 'on_site')<form method="POST" action="{{ route('field.visits.work-focus', $visit) }}">@csrf<button class="button-secondary">Work on this</button></form>@endif</div>
        <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $visit->serviceTicket->description ?: 'No detailed scope recorded.' }}</p>
    </div>

    <div class="surface p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">Additional work</p><h2 class="text-lg font-bold">Work Items</h2></div><span class="status-active">{{ $handledItems->count() }} handled here</span></div>
        @if($closeoutMissing->has('work_items'))<div class="mt-4 rounded-lg border border-red-400 bg-red-50 p-4 text-sm font-semibold text-red-900" role="alert">{{ $closeoutMissing->get('work_items') }}</div>@endif
        <div class="mt-4 space-y-3">
            @forelse($visit->serviceTicket->workItems as $workItem)
                @php($touchedHere = $workItem->visits->contains('id', $visit->id))
                <article class="rounded-lg border border-slate-200 p-4" data-v2-work-item="{{ $workItem->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="min-w-0 break-words font-bold">{{ $workItem->title }}</h3><span class="status-active">{{ Str::headline($workItem->status) }}</span></div>
                    <p class="mt-1 text-xs font-semibold text-slate-500">{{ $workItem->origin === 'field_discovered' ? 'Field discovered' : 'Office added' }}{{ $touchedHere ? ' · Handled this Visit' : '' }}@if($activeTimer?->service_ticket_work_item_id === $workItem->id) · Current focus @endif</p>
                    @if($workItem->detail)<p class="mt-3 whitespace-pre-line break-words text-sm text-slate-700">{{ $workItem->detail }}</p>@endif
                    @if($workItem->work_note)<p class="mt-3 whitespace-pre-line break-words rounded-lg bg-slate-50 p-3 text-sm"><strong>Work note:</strong> {{ $workItem->work_note }}</p>@endif
                    @if($workItem->followUpServiceTicket)<p class="mt-3 text-sm font-semibold">Follow-up: {{ $workItem->followUpServiceTicket->ticket_number }}</p>@endif
                    @if($activeTimer?->category === 'on_site' && in_array($workItem->status, ['open', 'needs_follow_up'], true))<form method="POST" action="{{ route('field.visits.work-focus', $visit) }}" class="mt-3">@csrf<input type="hidden" name="work_item_id" value="{{ $workItem->id }}"><button class="button-secondary w-full">Work on this</button></form>@endif
                    @if($workItemWritable && $workItem->status !== 'transferred')
                        <details class="mt-3 border-t border-slate-200 pt-2"><summary class="min-h-11 cursor-pointer py-3 font-bold text-brand-blue">Update Work Item</summary><form method="POST" action="{{ route('field.visits.work-items.update', [$visit, $workItem]) }}" class="space-y-3">@csrf @method('PUT')<label class="form-label" for="v2_work_status_{{ $workItem->id }}">Disposition</label><select class="form-input" id="v2_work_status_{{ $workItem->id }}" name="status" required>@foreach(['open' => 'Open', 'completed' => 'Completed', 'needs_follow_up' => 'Needs follow-up'] as $value => $label)<option value="{{ $value }}" @selected($workItem->status === $value)>{{ $label }}</option>@endforeach</select><label class="form-label" for="v2_work_note_{{ $workItem->id }}">Work note</label><textarea class="form-textarea" id="v2_work_note_{{ $workItem->id }}" name="work_note" maxlength="10000">{{ $workItem->work_note }}</textarea><button class="button-secondary w-full">Save Work Item</button></form></details>
                    @endif
                </article>
            @empty
                <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">No additional Work Items.</p>
            @endforelse
        </div>
        @if($workItemWritable)
            <details class="mt-5 rounded-lg border border-blue-200 bg-blue-50 p-4"><summary class="min-h-11 cursor-pointer py-2 font-bold text-brand-blue">Add discovered work</summary><form method="POST" action="{{ route('field.visits.work-items.store', $visit) }}" class="mt-3 space-y-3">@csrf<label class="form-label" for="v2_work_title">Title</label><input class="form-input" id="v2_work_title" name="title" required maxlength="255"><label class="form-label" for="v2_work_detail">Detail</label><textarea class="form-textarea" id="v2_work_detail" name="detail" maxlength="10000"></textarea><label class="form-label" for="v2_work_new_note">Work note</label><textarea class="form-textarea" id="v2_work_new_note" name="work_note" maxlength="10000"></textarea><button class="button-primary w-full">Add Work Item</button></form></details>
        @endif
    </div>
</section>
