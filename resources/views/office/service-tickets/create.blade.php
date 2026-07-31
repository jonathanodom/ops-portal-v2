<x-layouts.office title="New Service Ticket">
    <a href="{{ route('office.service-tickets.index') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-brand-blue">← Service Tickets</a>
    <h1 class="mt-2 text-3xl font-bold text-slate-950">New service ticket</h1>
    <form method="POST" action="{{ route('office.service-tickets.store') }}" class="surface mt-6 p-5 sm:p-6">
        @csrf
        <x-form-errors />
        @include('office.service-tickets._fields')
        <fieldset class="mt-6 border-t border-slate-200 pt-6">
            <legend class="text-lg font-bold text-slate-950">Optional first visit</legend>
            <label class="mt-4 flex min-h-11 items-center gap-3"><input type="checkbox" name="create_visit" value="1" @checked(old('create_visit'))><span class="font-semibold">Create the first visit now</span></label>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div><label class="form-label" for="scheduled_start">Local start</label><input type="datetime-local" class="form-input" id="scheduled_start" name="scheduled_start" value="{{ old('scheduled_start') }}"></div>
                <div><label class="form-label" for="scheduled_end">Local end</label><input type="datetime-local" class="form-input" id="scheduled_end" name="scheduled_end" value="{{ old('scheduled_end') }}"></div>
            </div>
            <p class="mt-2 text-sm text-slate-500">Times use the selected service location timezone. You may leave them blank for a planned visit.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach($memberships as $membership)
                    <label class="flex min-h-11 items-center gap-3 rounded-lg border border-slate-200 px-3"><input type="checkbox" name="assignees[]" value="{{ $membership->id }}" @checked(in_array($membership->id, old('assignees', [])))><span>{{ $membership->user->name }}</span></label>
                @endforeach
            </div>
            <div class="mt-4"><label class="form-label" for="lead_membership_id">Lead assignee</label><select class="form-input" id="lead_membership_id" name="lead_membership_id"><option value="">Choose lead</option>@foreach($memberships as $membership)<option value="{{ $membership->id }}" @selected((string)old('lead_membership_id')===(string)$membership->id)>{{ $membership->user->name }}</option>@endforeach</select></div>
            @error('schedule_conflict')<div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900">{{ $message }}<label class="mt-3 flex min-h-11 items-center gap-2"><input type="checkbox" name="confirm_conflicts" value="1"> Confirm overlapping schedule</label></div>@enderror
        </fieldset>
        <div class="mt-6 flex flex-wrap gap-3"><button class="button-primary">Create service ticket</button><a href="{{ route('office.service-tickets.index') }}" class="button-secondary">Cancel</a></div>
    </form>
</x-layouts.office>
