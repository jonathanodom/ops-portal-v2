<?php

namespace App\Http\Controllers\Office;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\Projects\Queries\ProjectWorkspaceQuery;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\ProjectWorkstream;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class ProjectController extends Controller
{
    public function index(Request $request, ProjectWorkspaceQuery $query): View
    {
        $organization = $this->organization($request);
        Gate::authorize('viewAny', [Project::class, $organization]);

        return view('office.projects.index', $query->workspace($organization, $request));
    }

    public function create(Request $request, CustomerDirectory $customers): View
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [Project::class, $organization]);
        $customerId = $request->integer('customer_id') ?: null;

        return view('office.projects.create', [
            'customers' => $customers->search($organization),
            'locations' => $customerId ? $customers->locations($organization, $customerId) : collect(),
            'contacts' => $customerId ? $customers->contacts($organization, $customerId) : collect(),
            'members' => $organization->memberships()->where('status', 'active')->with('user:id,name')->get(),
            'customerId' => $customerId,
        ]);
    }

    public function store(Request $request, ProjectWorkflow $workflow): RedirectResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [Project::class, $organization]);
        $project = $workflow->create($organization, $request->user(), $this->projectData($request));

        return redirect()->route('office.projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project, ProjectWorkspaceQuery $query): View
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('view', $project);

        return view('office.projects.show', $query->detail($this->organization($request), $project));
    }

    public function update(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('update', $project);
        $workflow->update($project, $request->user(), $this->projectData($request));

        return back()->with('status', 'Project updated.');
    }

    public function storeWorkstream(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('update', $project);
        $workflow->addWorkstream($project, $request->user(), $this->workstreamData($request));

        return back()->with('status', 'Workstream added.');
    }

    public function updateWorkstream(Request $request, Project $project, ProjectWorkstream $workstream, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('update', $project);
        $workflow->updateWorkstream($project, $workstream, $request->user(), $this->workstreamData($request));

        return back()->with('status', 'Workstream updated.');
    }

    public function storeTask(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('manageTasks', $project);
        $workflow->addTask($project, $request->user(), $this->taskData($request));

        return back()->with('status', 'Task added.');
    }

    public function updateTask(Request $request, Project $project, ProjectTask $task, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('manageTasks', $project);
        $workflow->updateTask($project, $task, $request->user(), $this->taskData($request));

        return back()->with('status', 'Task updated.');
    }

    public function storeMilestone(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('update', $project);
        $workflow->addMilestone($project, $request->user(), $this->milestoneData($request));

        return back()->with('status', 'Milestone added.');
    }

    public function updateMilestone(Request $request, Project $project, ProjectMilestone $milestone, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('update', $project);
        $workflow->updateMilestone($project, $milestone, $request->user(), $this->milestoneData($request));

        return back()->with('status', 'Milestone updated.');
    }

    public function storeNote(Request $request, Project $project, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('manageTasks', $project);
        $workflow->addNote($project, $request->user(), $request->validate(['type' => ['required', 'in:'.implode(',', ProjectWorkflow::NOTE_TYPES)], 'body' => ['required', 'string', 'max:10000']]));

        return back()->with('status', 'Internal note added.');
    }

    public function linkTicket(Request $request, Project $project, ServiceOperationsDirectory $operations, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('administer', $project);
        $data = $request->validate(['service_ticket_id' => ['required', 'integer'], 'confirm_location_mismatch' => ['sometimes', 'boolean']]);
        $workflow->linkTicket($project, $operations->resolve($this->organization($request), (int) $data['service_ticket_id']), $request->user(), $request->boolean('confirm_location_mismatch'));

        return back()->with('status', 'Service Ticket linked.');
    }

    public function unlinkTicket(Request $request, Project $project, int $ticket, ServiceOperationsDirectory $operations, ProjectWorkflow $workflow): RedirectResponse
    {
        $project = $this->scoped($request, $project);
        Gate::authorize('administer', $project);
        $operations->resolve($this->organization($request), $ticket);
        $workflow->unlinkTicket($project, $ticket, $request->user());

        return back()->with('status', 'Service Ticket unlinked.');
    }

    private function projectData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:'.implode(',', ProjectWorkflow::TYPES)],
            'status' => ['required', 'in:'.implode(',', ProjectWorkflow::STATUSES)], 'customer_id' => ['nullable', 'integer'],
            'service_location_id' => ['nullable', 'integer'], 'primary_contact_id' => ['nullable', 'integer'], 'owner_user_id' => ['nullable', 'integer'],
            'start_on' => ['nullable', 'date'], 'target_end_on' => ['nullable', 'date'], 'summary' => ['nullable', 'string', 'max:5000'], 'objective' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function workstreamData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['required', 'in:'.implode(',', ProjectWorkflow::WORKSTREAM_STATUSES)], 'owner_user_id' => ['nullable', 'integer'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
    }

    private function taskData(Request $request): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['required', 'in:'.implode(',', ProjectWorkflow::TASK_STATUSES)], 'priority' => ['required', 'in:'.implode(',', ProjectWorkflow::TASK_PRIORITIES)], 'workstream_id' => ['nullable', 'integer'], 'assigned_to_user_id' => ['nullable', 'integer'], 'start_on' => ['nullable', 'date'], 'due_on' => ['nullable', 'date'], 'blocked_reason' => ['nullable', 'string', 'max:2000'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
    }

    private function milestoneData(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['required', 'in:'.implode(',', ProjectWorkflow::MILESTONE_STATUSES)], 'target_on' => ['nullable', 'date'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function scoped(Request $request, Project $project): Project
    {
        abort_unless($project->organization_id === $this->organization($request)->id, 404);

        return $project;
    }
}
