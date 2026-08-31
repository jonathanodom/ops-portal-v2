<x-layouts.office title="Leads" width="workspace">
    @if(session('status'))<x-office.alert>{{ session('status') }}</x-office.alert>@endif
    <form method="GET" aria-label="Lead filters">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <x-office.primary-toolbar title="Leads" eyebrow="Commercial" description="Review website inquiries and convert qualified work into Opportunities.">
            <x-slot:search><label class="sr-only" for="lead-search">Search leads</label><input class="form-input" id="lead-search" name="search" value="{{ request('search') }}" placeholder="Search name, company, email, phone, or service"></x-slot:search>
            <x-slot:viewSwitcher>
                <x-office.view-switcher aria-label="Lead status">
                    @foreach(['open'=>'Open','converted'=>'Converted','archived'=>'Archived','spam'=>'Spam','all'=>'All'] as $value=>$label)
                        <a @if($filter===$value) aria-current="page" @endif href="{{ route('office.leads.index',array_filter(['filter'=>$value,'search'=>request('search')])) }}">{{ $label }}</a>
                    @endforeach
                </x-office.view-switcher>
            </x-slot:viewSwitcher>
            @can('create', [App\Models\CommercialLeadIntake::class, $activeOrganization])
                <x-slot:primaryAction><a class="button-primary" href="{{ route('office.leads.create') }}">New lead</a></x-slot:primaryAction>
            @endcan
        </x-office.primary-toolbar>
    </form>

    @if($leads->isEmpty())
        <x-office.state-panel class="mt-5" title="No matching leads" message="No lead intakes match this status and search." />
    @else
        <div class="office-table-wrap">
            <table class="office-data-table">
                <caption class="sr-only">Commercial lead intakes</caption>
                <thead><tr><th scope="col">Lead</th><th scope="col">Company</th><th scope="col">Service interest</th><th scope="col">Preferred contact</th><th scope="col">Received</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
                <tbody>
                    @foreach($leads as $lead)
                        <tr>
                            <td><strong>{{ $lead->first_name }} {{ $lead->last_name }}</strong><span class="block text-xs text-slate-500">{{ $lead->email }} · {{ $lead->phone }}</span></td>
                            <td>{{ $lead->company ?: 'Individual' }}</td>
                            <td>{{ $lead->service_interest }}</td>
                            <td>{{ $lead->preferred_contact }}</td>
                            <td><time datetime="{{ $lead->received_at->toAtomString() }}">{{ $lead->received_at->timezone($activeOrganization->timezone)->format('M j, Y g:i A') }}</time></td>
                            <td><span class="{{ $lead->status === 'received' ? 'status-priority' : ($lead->status === 'converted' ? 'status-success' : 'status-muted') }}">{{ str($lead->status)->headline() }}</span></td>
                            <td class="text-right"><a class="button-secondary" href="{{ route('office.leads.show',$lead) }}">Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="office-mobile-list">
            @foreach($leads as $lead)
                <article class="office-mobile-card">
                    <div class="flex items-start justify-between gap-3"><div><h2 class="font-bold">{{ $lead->first_name }} {{ $lead->last_name }}</h2><p class="mt-1 text-sm text-slate-600">{{ $lead->company ?: $lead->customer_type }}</p></div><span class="{{ $lead->status === 'received' ? 'status-priority' : ($lead->status === 'converted' ? 'status-success' : 'status-muted') }}">{{ str($lead->status)->headline() }}</span></div>
                    <p class="mt-3 text-sm font-semibold">{{ $lead->service_interest }}</p><p class="mt-1 text-xs text-slate-500">Received {{ $lead->received_at->timezone($activeOrganization->timezone)->format('M j, Y g:i A') }}</p>
                    <a class="button-secondary mt-4 w-full" href="{{ route('office.leads.show',$lead) }}">Open lead</a>
                </article>
            @endforeach
        </div>
        <div class="mt-5">{{ $leads->links() }}</div>
    @endif
</x-layouts.office>
