<?php

namespace App\Domain\Projects\Queries;

use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectTask;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProjectWorkspaceQuery
{
    public function __construct(private readonly CustomerDirectory $customers, private readonly ServiceOperationsDirectory $operations) {}

    public function workspace(Organization $organization, Request $request): array
    {
        $today = CarbonImmutable::now($organization->timezone)->toDateString();
        $query = Project::query()->forOrganization($organization->id)->with('owner:id,name')
            ->withCount([
                'tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['done', 'canceled']),
                'tasks as overdue_tasks_count' => fn ($q) => $q->whereNotIn('status', ['done', 'canceled'])->whereDate('due_on', '<', $today),
                'tasks as blocked_tasks_count' => fn ($q) => $q->where('status', 'blocked'),
            ]);

        $search = trim($request->string('search')->value());
        $query->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('project_number', 'like', "%{$search}%")))
            ->when($request->integer('customer_id'), fn ($q, int $id) => $q->where('customer_id', $id))
            ->when($request->string('type')->value(), fn ($q, string $type) => $q->where('type', $type))
            ->when($request->string('status')->value(), fn ($q, string $status) => $q->where('status', $status))
            ->when($request->integer('owner_user_id'), fn ($q, int $id) => $q->where('owner_user_id', $id))
            ->when($request->boolean('has_overdue_tasks'), fn ($q) => $q->whereHas('tasks', fn ($tasks) => $tasks->whereNotIn('status', ['done', 'canceled'])->whereDate('due_on', '<', $today)));

        $projects = $query->latest('updated_at')->paginate(20)->withQueryString();
        $customerMap = $this->customers->summaries($organization, $projects->getCollection()->pluck('customer_id')->filter()->unique()->values()->all());

        return [
            'projects' => $projects,
            'customerMap' => $customerMap,
            'customers' => $this->customers->search($organization),
            'members' => $organization->memberships()->where('status', 'active')->with('user:id,name')->get(),
            'attention' => [
                'active' => Project::query()->forOrganization($organization->id)->where('status', 'active')->count(),
                'due_today' => ProjectTask::query()->where('organization_id', $organization->id)->whereDate('due_on', $today)->whereNotIn('status', ['done', 'canceled'])->count(),
                'overdue' => ProjectTask::query()->where('organization_id', $organization->id)->whereDate('due_on', '<', $today)->whereNotIn('status', ['done', 'canceled'])->count(),
                'blocked' => ProjectTask::query()->where('organization_id', $organization->id)->where('status', 'blocked')->count(),
                'milestones' => Project::query()->forOrganization($organization->id)->whereHas('milestones', fn ($q) => $q->whereNotIn('status', ['completed', 'canceled'])->whereBetween('target_on', [$today, CarbonImmutable::parse($today, $organization->timezone)->addDays(30)->toDateString()]))->count(),
            ],
        ];
    }

    public function detail(Organization $organization, Project $project): array
    {
        $project->load(['owner:id,name', 'workstreams.owner:id,name', 'workstreams.tasks.assignee:id,name', 'tasks.workstream:id,name', 'tasks.assignee:id,name', 'milestones', 'notes.author:id,name', 'storedAttachments.uploader:id,name']);
        $customer = $project->customer_id ? $this->customers->resolve($organization, $project->customer_id) : null;
        $locations = $project->customer_id ? $this->customers->locations($organization, $project->customer_id) : collect();
        $contacts = $project->customer_id ? $this->customers->contacts($organization, $project->customer_id) : collect();
        $linkedIds = DB::table('project_service_ticket')->where('organization_id', $organization->id)->where('project_id', $project->id)->pluck('service_ticket_id')->all();
        $tickets = $this->operations->summaries($organization, $linkedIds);
        $availableTickets = $project->customer_id ? $this->operations->forCustomer($organization, $project->customer_id)->except($linkedIds) : collect();

        return compact('project', 'customer', 'locations', 'contacts', 'tickets', 'availableTickets') + [
            'customers' => $this->customers->search($organization),
            'customerId' => $project->customer_id,
            'members' => $organization->memberships()->where('status', 'active')->with('user:id,name')->get(),
            'activity' => AuditEvent::query()->where('organization_id', $organization->id)->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->with('actor:id,name')->latest('occurred_at')->limit(50)->get(),
        ];
    }
}
