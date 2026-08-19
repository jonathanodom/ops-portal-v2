<x-layouts.print :title="'Service Work Order '.$ticket->ticket_number">
    @php
        $contact = $ticket->contact ?: $ticket->serviceLocation->primaryContact;
        $purposeLabel = config('service_tickets.purposes.'.$ticket->purpose, Str::headline($ticket->purpose));
        $billingLabel = config('service_tickets.billing_dispositions.'.$ticket->billing_disposition, Str::headline($ticket->billing_disposition));
    @endphp
    <div class="print-toolbar print-screen-only" aria-label="Document actions">
        <a href="{{ route('office.service-tickets.show', $ticket) }}" class="print-toolbar-link">← Back to Service Ticket</a>
        <button type="button" class="print-toolbar-button" onclick="window.print()">Print</button>
    </div>
    <main class="print-sheet" data-print-document="service-work-order">
        <header class="print-document-header">
            <div class="print-brand"><x-organization-logo variant="full" class="print-brand-logo" /><div><p class="print-brand-name">{{ $activeOrganization->name }}</p><p class="print-muted">Internal Operational Copy</p></div></div>
            <div class="print-document-identity"><p class="print-kicker">SERVICE WORK ORDER</p><h1>{{ $ticket->ticket_number }}</h1><p><strong>Status:</strong> {{ Str::headline($ticket->status) }}</p></div>
        </header>

        <p class="print-snapshot">Generated from the live Ops Portal record on {{ $generatedAt->format('M j, Y g:i A T') }}. This document reflects the current record state at that time.</p>

        <section class="print-section" aria-labelledby="customer-site-heading">
            <h2 id="customer-site-heading">Customer / Site</h2>
            <div class="print-grid print-grid-2">
                <dl class="print-data"><dt>Customer</dt><dd>{{ $ticket->customer->display_name }}</dd>@if($ticket->customer->legal_name)<dt>Legal name</dt><dd>{{ $ticket->customer->legal_name }}</dd>@endif</dl>
                <dl class="print-data"><dt>Service location</dt><dd>{{ $ticket->serviceLocation->name }}</dd><dt>Address</dt><dd>{{ $ticket->serviceLocation->formattedAddress() }}</dd><dt>Timezone</dt><dd>{{ $ticket->serviceLocation->timezone }}</dd></dl>
            </div>
            @if($contact)
                <dl class="print-data print-contact"><dt>Point of contact</dt><dd>{{ $contact->name }}@if($contact->role) · {{ $contact->role }}@endif</dd>@if($contact->phone)<dt>Phone</dt><dd>{{ $contact->phone }}</dd>@endif @if($contact->email)<dt>Email</dt><dd>{{ $contact->email }}</dd>@endif</dl>
            @endif
        </section>

        <section class="print-section" aria-labelledby="request-heading">
            <h2 id="request-heading">Request</h2>
            <h3>{{ $ticket->title }}</h3>
            <div class="print-grid print-grid-4 print-compact-meta"><p><strong>Priority</strong><br>{{ Str::headline($ticket->priority) }}</p><p><strong>Source</strong><br>{{ Str::headline($ticket->source) }}</p><p><strong>Purpose</strong><br>{{ $purposeLabel }}</p><p><strong>Billing disposition</strong><br>{{ $billingLabel }}</p></div>
            <div class="print-prose"><h3>Work scope</h3><p>{{ $ticket->description ?: 'No work scope recorded.' }}</p></div>
            @if($ticket->customer_visible_summary)<div class="print-prose"><h3>Customer-visible summary</h3><p>{{ $ticket->customer_visible_summary }}</p></div>@endif
        </section>

        @if($ticket->projects->isNotEmpty())
            <section class="print-section" aria-labelledby="projects-heading"><h2 id="projects-heading">Related Project Context</h2><table class="print-table"><thead><tr><th>Project</th><th>Name</th><th>Status</th></tr></thead><tbody>@foreach($ticket->projects as $project)<tr><td>{{ $project->project_number }}</td><td>{{ $project->name }}</td><td>{{ Str::headline($project->status) }}</td></tr>@endforeach</tbody></table></section>
        @endif

        <section class="print-section print-page-break" aria-labelledby="visits-heading">
            <h2 id="visits-heading">Visit Schedule / Crew</h2>
            @forelse($ticket->visits as $visit)
                @php
                    $closeout = $visit->currentCloseout;
                    $timeSeconds = $visit->timeEntries->sum(fn ($entry) => $entry->ended_at ? $entry->started_at->diffInSeconds($entry->ended_at) : 0);
                    $categories = $closeout?->media->groupBy('category')->map->count() ?? collect();
                @endphp
                <article class="print-record">
                    <div class="print-record-heading"><h3>{{ $visit->displayLabel() }}</h3><p>{{ Str::headline($visit->status) }}</p></div>
                    <div class="print-grid print-grid-2"><p><strong>Schedule</strong><br>{{ $visit->scheduledStartLocal()?->format('M j, Y g:i A T') ?? 'Unscheduled' }}@if($visit->scheduledEndLocal()) – {{ $visit->scheduledEndLocal()->format('g:i A T') }}@endif</p><p><strong>Location</strong><br>{{ $visit->serviceLocation?->name ?? $ticket->serviceLocation->name }}</p><p><strong>Crew</strong><br>{{ $visit->assignments->map(fn ($assignment) => ($assignment->is_lead ? 'Lead: ' : '').$assignment->membership->user->name)->join(', ') ?: 'No crew assigned' }}</p><p><strong>Completed technician time</strong><br>{{ number_format($timeSeconds / 3600, 2) }} hours</p></div>
                    @if($includeCloseoutEvidence && $closeout && $closeout->status === 'submitted')
                        <div class="print-subsection"><h3>Execution / Completion Summary</h3><dl class="print-data"><dt>Outcome</dt><dd>{{ Str::headline($closeout->outcome) }}</dd>@foreach(['diagnosis'=>'Diagnosis','work_performed'=>'Work performed','return_reason'=>'Return-trip reason','unfinished_work'=>'Unfinished work','needed_equipment'=>'Needed equipment','recommendations'=>'Recommendations','unavailable_category'=>'Customer-unavailable category','unavailable_detail'=>'Customer-unavailable detail'] as $field => $label)@if(filled($closeout->{$field}))<dt>{{ $label }}</dt><dd class="print-preserve-lines">{{ $closeout->{$field} }}</dd>@endif @endforeach</dl></div>
                        @if($closeout->parts->isNotEmpty())<div class="print-subsection"><h3>Parts / Items</h3><table class="print-table"><thead><tr><th>Description</th><th>Quantity</th><th>Treatment</th></tr></thead><tbody>@foreach($closeout->parts as $part)<tr><td>{{ $part->description }}</td><td>{{ $part->quantity }} {{ $part->unit }}</td><td>{{ Str::headline($part->billing_treatment) }}</td></tr>@endforeach</tbody></table></div>@endif
                        <p class="print-evidence"><strong>Evidence:</strong> {{ $closeout->media->count() }} active file(s)@if($categories->isNotEmpty()) · {{ $categories->map(fn ($count, $category) => Str::headline($category).': '.$count)->join(', ') }}@endif</p>
                    @elseif($closeout)
                        <p class="print-muted"><strong>Closeout:</strong> {{ Str::headline($closeout->status) }}@if(!$includeCloseoutEvidence) · Detailed field evidence is restricted for this user.@endif</p>
                    @endif
                </article>
            @empty
                <p class="print-empty">No Visits have been created.</p>
            @endforelse
        </section>

        <section class="print-section" aria-labelledby="files-heading"><h2 id="files-heading">Files / Evidence Index</h2>@if($ticket->files->isNotEmpty())<table class="print-table"><thead><tr><th>File</th><th>Type</th><th>Caption</th><th>Uploaded</th></tr></thead><tbody>@foreach($ticket->files as $file)<tr><td>{{ $file->original_name }}</td><td>{{ $file->mime_type }}</td><td>{{ $file->caption ?: '—' }}</td><td>{{ $file->created_at->timezone($activeOrganization->timezone)->format('M j, Y') }}</td></tr>@endforeach</tbody></table>@else<p class="print-empty">No active Ticket files.</p>@endif</section>

        <footer class="print-document-footer"><span>{{ $ticket->ticket_number }}</span><span>Generated {{ $generatedAt->format('M j, Y g:i A T') }}</span><span>Live operational snapshot</span></footer>
    </main>
</x-layouts.print>
