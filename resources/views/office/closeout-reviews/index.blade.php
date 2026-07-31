<x-layouts.office title="Closeout review">
    @if(session('status'))<div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 font-semibold text-emerald-900" role="status">{{ session('status') }}</div>@endif
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-sm font-bold text-brand-blue">Office review</p><h1 class="mt-1 text-3xl font-bold text-slate-950">Closeout queue</h1><p class="mt-2 text-slate-600">Submitted field work awaiting an operational decision.</p></div>
    </div>
    <form method="GET" class="surface mt-6 grid gap-4 p-4 md:grid-cols-4 xl:grid-cols-7">
        <div><label class="form-label" for="customer">Customer</label><input class="form-input" id="customer" name="customer" value="{{ request('customer') }}" placeholder="Name"></div>
        <div><label class="form-label" for="outcome">Outcome</label><select class="form-input" id="outcome" name="outcome"><option value="">All</option>@foreach(['resolved','needs_return_trip','on_hold','customer_unavailable'] as $outcome)<option value="{{ $outcome }}" @selected(request('outcome')===$outcome)>{{ ucfirst(str_replace('_',' ',$outcome)) }}</option>@endforeach</select></div>
        <div><label class="form-label" for="priority">Priority</label><select class="form-input" id="priority" name="priority"><option value="">All</option>@foreach(config('service_tickets.priorities') as $key=>$label)<option value="{{ $key }}" @selected(request('priority')===$key)>{{ $label }}</option>@endforeach</select></div>
        <div><label class="form-label" for="technician">Submitted by</label><select class="form-input" id="technician" name="technician"><option value="">Anyone</option>@foreach($technicians as $technician)<option value="{{ $technician->id }}" @selected((string)request('technician')===(string)$technician->id)>{{ $technician->name }}</option>@endforeach</select></div>
        <div><label class="form-label" for="age">At least</label><select class="form-input" id="age" name="age"><option value="">Any age</option><option value="1" @selected(request('age')==='1')>1 day</option><option value="3" @selected(request('age')==='3')>3 days</option><option value="7" @selected(request('age')==='7')>7 days</option></select></div>
        <div><label class="form-label" for="correction_state">Submission</label><select class="form-input" id="correction_state" name="correction_state"><option value="">All</option><option value="first_submission" @selected(request('correction_state')==='first_submission')>First submission</option><option value="resubmitted" @selected(request('correction_state')==='resubmitted')>Resubmitted</option></select></div>
        <button class="button-primary self-end">Apply filters</button>
    </form>
    <div class="surface mt-6 overflow-hidden">
        @forelse($closeouts as $closeout)
            <a href="{{ route('office.closeout-reviews.show',$closeout) }}" class="block min-h-11 border-b border-slate-200 p-5 last:border-0 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-blue">
                <div class="flex flex-wrap justify-between gap-3"><div><p class="font-bold text-slate-950">{{ $closeout->visit->serviceTicket->ticket_number }} · {{ $closeout->visit->serviceTicket->customer->display_name }}</p><p class="mt-1 text-sm text-slate-600">{{ $closeout->visit->serviceLocation->name }} · Visit #{{ $closeout->visit_id }} · Version {{ $closeout->version }}</p></div><div class="text-right"><p class="font-bold {{ in_array($closeout->visit->serviceTicket->priority,['high','urgent']) ? 'text-orange-700' : 'text-brand-blue' }}">{{ ucfirst(str_replace('_',' ',$closeout->outcome)) }}</p><p class="mt-1 text-xs text-slate-500">{{ $closeout->submittedBy?->name ?? 'Former user' }} · <x-local-time :value="$closeout->submitted_at" :timezone="$closeout->visit->timezone" /></p></div></div>
            </a>
        @empty<div class="p-8 text-center"><p class="font-bold text-slate-900">Review queue clear</p><p class="mt-2 text-sm text-slate-500">No submitted closeouts match these filters.</p></div>@endforelse
    </div>
    <div class="mt-5">{{ $closeouts->links() }}</div>
</x-layouts.office>
