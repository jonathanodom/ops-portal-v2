<x-layouts.print :title="'Project Workbook '.$project->project_number">
    <div class="print-toolbar print-screen-only" aria-label="Document actions">
        <a href="{{ route('office.projects.show', $project) }}" class="print-toolbar-link">← Back to Project</a>
        <button type="button" class="print-toolbar-button" onclick="window.print()">Print</button>
    </div>
    <main class="print-sheet print-workbook" data-print-document="project-workbook">
        <header class="print-document-header print-cover-header">
            <div class="print-brand"><x-organization-logo variant="full" class="print-brand-logo" /><div><p class="print-brand-name">{{ $activeOrganization->name }}</p><p class="print-muted">Internal Operational Copy</p></div></div>
            <div class="print-document-identity"><p class="print-kicker">PROJECT WORKBOOK</p><h1>{{ $project->project_number }}</h1><p><strong>Status:</strong> {{ Str::headline($project->status) }}</p></div>
        </header>
        <section class="print-cover">
            <h2>{{ $project->name }}</h2>
            <div class="print-grid print-grid-2"><dl class="print-data"><dt>Type</dt><dd>{{ Str::headline($project->type) }}</dd><dt>Owner</dt><dd>{{ $project->owner?->name ?? 'Unassigned' }}</dd><dt>Start date</dt><dd>{{ $project->start_on?->format('M j, Y') ?? 'Not set' }}</dd></dl><dl class="print-data"><dt>Target end</dt><dd>{{ $project->target_end_on?->format('M j, Y') ?? 'Ongoing' }}</dd>@if($project->completed_at)<dt>Completed</dt><dd>{{ $project->completed_at->timezone($activeOrganization->timezone)->format('M j, Y') }}</dd>@endif<dt>Generated</dt><dd>{{ $generatedAt->format('M j, Y g:i A T') }}</dd></dl></div>
            <p class="print-snapshot">Generated from the live Ops Portal record on {{ $generatedAt->format('M j, Y g:i A T') }}. This workbook reflects the current Project state at that time.</p>
        </section>

        <section class="print-section print-page-break" aria-labelledby="project-context-heading"><h2 id="project-context-heading">Customer / Site / Contact</h2>
            @if($customer)
                <div class="print-grid print-grid-3"><dl class="print-data"><dt>Customer</dt><dd>{{ $customer->displayName }}</dd>@if($customer->legalName)<dt>Legal name</dt><dd>{{ $customer->legalName }}</dd>@endif @if($customer->phone)<dt>Phone</dt><dd>{{ $customer->phone }}</dd>@endif @if($customer->email)<dt>Email</dt><dd>{{ $customer->email }}</dd>@endif</dl><dl class="print-data"><dt>Primary site</dt><dd>{{ $location?->name ?? 'Customer-wide / multi-site' }}</dd>@if($location)<dt>Address</dt><dd>{{ $location->address }}</dd><dt>Timezone</dt><dd>{{ $location->timezone }}</dd>@endif</dl><dl class="print-data"><dt>Primary contact</dt><dd>{{ $contact?->name ?? 'Not assigned' }}</dd>@if($contact?->role)<dt>Role</dt><dd>{{ $contact->role }}</dd>@endif @if($contact?->phone)<dt>Phone</dt><dd>{{ $contact->phone }}</dd>@endif @if($contact?->email)<dt>Email</dt><dd>{{ $contact->email }}</dd>@endif</dl></div>
            @else
                <p class="print-internal-label">Internal Project</p><p class="print-muted">This Project is not associated with a Customer or Service Location.</p>
            @endif
        </section>

        <section class="print-section" aria-labelledby="definition-heading"><h2 id="definition-heading">Project Definition</h2><div class="print-grid print-grid-2"><div class="print-prose"><h3>Summary</h3><p>{{ $project->summary ?: 'No summary recorded.' }}</p></div><div class="print-prose"><h3>Objective</h3><p>{{ $project->objective ?: 'No objective recorded.' }}</p></div></div></section>

        <section class="print-section print-page-break" aria-labelledby="workstreams-heading"><h2 id="workstreams-heading">Workstreams</h2>@forelse($project->workstreams as $workstream)<article class="print-record"><div class="print-record-heading"><h3>{{ $workstream->name }}</h3><p>{{ Str::headline($workstream->status) }}</p></div><p><strong>Owner:</strong> {{ $workstream->owner?->name ?? 'Unassigned' }}</p>@if($workstream->description)<p class="print-preserve-lines">{{ $workstream->description }}</p>@endif</article>@empty<p class="print-empty">No Workstreams recorded.</p>@endforelse</section>

        <section class="print-section print-page-break" aria-labelledby="tasks-heading"><h2 id="tasks-heading">Tasks</h2>
            @php($taskGroups = $project->tasks->groupBy(fn ($task) => $task->workstream?->name ?? 'No Workstream'))
            @forelse($taskGroups as $group => $tasks)
                <div class="print-task-group"><h3>{{ $group }}</h3><table class="print-table"><thead><tr><th>Task</th><th>Status / Priority</th><th>Assignee</th><th>Dates</th></tr></thead><tbody>@foreach($tasks as $task)@php($overdue = $task->due_on && $task->due_on->lt($today) && !in_array($task->status, ['done','canceled'], true))<tr><td><strong>{{ $task->title }}</strong>@if($task->description)<br><span>{{ $task->description }}</span>@endif @if($task->blocked_reason)<br><strong>Blocked:</strong> {{ $task->blocked_reason }}@endif</td><td>{{ Str::headline($task->status) }}<br>{{ Str::headline($task->priority) }}@if($overdue)<br><strong>Overdue</strong>@endif</td><td>{{ $task->assignee?->name ?? 'Unassigned' }}</td><td>{{ $task->start_on?->format('M j, Y') ?? 'No start' }}<br>Due {{ $task->due_on?->format('M j, Y') ?? 'not set' }}</td></tr>@endforeach</tbody></table></div>
            @empty<p class="print-empty">No Tasks recorded.</p>@endforelse
        </section>

        <section class="print-section print-page-break" aria-labelledby="milestones-heading"><h2 id="milestones-heading">Milestones</h2>@if($project->milestones->isNotEmpty())<table class="print-table"><thead><tr><th>Milestone</th><th>Status</th><th>Target</th><th>Completed</th></tr></thead><tbody>@foreach($project->milestones as $milestone)<tr><td><strong>{{ $milestone->name }}</strong>@if($milestone->description)<br>{{ $milestone->description }}@endif</td><td>{{ Str::headline($milestone->status) }}</td><td>{{ $milestone->target_on?->format('M j, Y') ?? '—' }}</td><td>{{ $milestone->completed_on?->format('M j, Y') ?? '—' }}</td></tr>@endforeach</tbody></table>@else<p class="print-empty">No Milestones recorded.</p>@endif</section>

        <section class="print-section print-page-break" aria-labelledby="related-tickets-heading"><h2 id="related-tickets-heading">Related Service Tickets</h2>@if($tickets->isNotEmpty())<table class="print-table"><thead><tr><th>Ticket</th><th>Purpose</th><th>Location</th><th>Status / Visits</th></tr></thead><tbody>@foreach($tickets as $ticket)<tr><td><strong>{{ $ticket->ticketNumber }}</strong><br>{{ $ticket->title }}</td><td>{{ Str::headline($ticket->purpose) }}<br>{{ Str::headline($ticket->priority) }} priority</td><td>{{ $ticket->locationName }}</td><td>{{ Str::headline($ticket->status) }}<br>{{ $ticket->visitCount }} {{ Str::plural('Visit', $ticket->visitCount) }}</td></tr>@endforeach</tbody></table>@else<p class="print-empty">No related Service Tickets.</p>@endif</section>

        <section class="print-section print-page-break" aria-labelledby="attachments-heading"><h2 id="attachments-heading">Project Files &amp; Photos Index</h2>@if($project->storedAttachments->isNotEmpty())<table class="print-table"><thead><tr><th>Category / File</th><th>Caption</th><th>Uploaded by / Date</th><th>Size / Type</th></tr></thead><tbody>@foreach($project->storedAttachments as $attachment)<tr><td>{{ config('project_attachments.categories.'.$attachment->category, Str::headline($attachment->category)) }}<br><strong>{{ $attachment->original_name }}</strong></td><td>{{ $attachment->caption ?: '—' }}</td><td>{{ $attachment->uploader?->name ?? 'Former user' }}<br>{{ $attachment->created_at->timezone($activeOrganization->timezone)->format('M j, Y g:i A') }}</td><td>{{ Illuminate\Support\Number::fileSize($attachment->byte_size) }}<br>{{ $attachment->mime_type }}</td></tr>@endforeach</tbody></table>@else<p class="print-empty">No active Project files or photos.</p>@endif</section>

        <section class="print-section print-page-break" aria-labelledby="notes-heading"><h2 id="notes-heading">Project Notes</h2>@forelse($project->notes as $note)<article class="print-record"><div class="print-record-heading"><h3>{{ Str::headline($note->type) }}</h3><p>{{ $note->author?->name ?? 'Former user' }} · {{ $note->created_at->timezone($activeOrganization->timezone)->format('M j, Y g:i A') }}</p></div><p class="print-preserve-lines">{{ $note->body }}</p></article>@empty<p class="print-empty">No Project Notes recorded.</p>@endforelse</section>

        <footer class="print-document-footer"><span>{{ $project->project_number }}</span><span>Generated {{ $generatedAt->format('M j, Y g:i A T') }}</span><span>Internal Operational Copy · Live project snapshot</span></footer>
    </main>
</x-layouts.print>
