<?php

namespace App\Domain\Projects\Queries;

use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Models\Organization;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProjectWorkbookQuery
{
    public function __construct(
        private readonly CustomerDirectory $customers,
        private readonly ServiceOperationsDirectory $operations,
    ) {}

    public function build(Organization $organization, Project $project): array
    {
        abort_unless($project->organization_id === $organization->id, 404);

        $project->load([
            'owner:id,name',
            'workstreams' => fn ($query) => $query->with('owner:id,name')->orderBy('sort_order')->orderBy('id')->limit(500),
            'tasks' => fn ($query) => $query->with(['workstream:id,name', 'assignee:id,name'])->orderBy('sort_order')->orderBy('id')->limit(500),
            'milestones' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')->limit(500),
            'notes' => fn ($query) => $query->with('author:id,name')->latest()->limit(100),
            'storedAttachments' => fn ($query) => $query->with('uploader:id,name')->latest()->limit(100),
        ]);

        $customer = $project->customer_id ? $this->customers->resolve($organization, $project->customer_id) : null;
        $location = $project->customer_id && $project->service_location_id
            ? $this->customers->locations($organization, $project->customer_id)->get($project->service_location_id)
            : null;
        $contact = $project->customer_id && $project->primary_contact_id
            ? $this->customers->contacts($organization, $project->customer_id)->get($project->primary_contact_id)
            : null;
        $ticketIds = DB::table('project_service_ticket')
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->orderBy('service_ticket_id')
            ->limit(200)
            ->pluck('service_ticket_id')
            ->all();

        return [
            'project' => $project,
            'customer' => $customer,
            'location' => $location,
            'contact' => $contact,
            'tickets' => $this->operations->summaries($organization, $ticketIds),
            'generatedAt' => CarbonImmutable::now($organization->timezone),
            'today' => CarbonImmutable::now($organization->timezone)->startOfDay(),
        ];
    }
}
