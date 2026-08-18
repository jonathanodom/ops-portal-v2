<?php

namespace App\Http\Controllers\Office;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\ServiceTicketCreationValidator;
use App\Domain\ServiceTicketCreator;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ServiceTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ProjectServiceTicketController extends Controller
{
    public function create(Request $request, Project $project, CustomerDirectory $customers): View
    {
        $organization = $this->organization($request);
        $project = $this->scoped($organization, $project);
        $this->authorize($organization, $project);
        $this->requireCustomer($project);

        return view('office.projects.service-tickets.create', [
            'project' => $project,
            'projectCustomer' => $customers->resolve($organization, $project->customer_id),
            'projectLocations' => $customers->locations($organization, $project->customer_id)->filter->active,
            'projectContacts' => $customers->contacts($organization, $project->customer_id)->filter->active,
            'memberships' => $this->fieldMemberships($organization),
            ...$this->options(),
        ]);
    }

    public function store(
        Request $request,
        Project $project,
        ServiceTicketCreationValidator $validator,
        ServiceTicketCreator $creator,
        ServiceOperationsDirectory $operations,
        ProjectWorkflow $projects,
    ): RedirectResponse {
        $organization = $this->organization($request);
        $project = $this->scoped($organization, $project);
        $this->authorize($organization, $project);
        $this->requireCustomer($project);
        $data = $validator->validate($request, $organization, $project->customer_id);

        $ticket = DB::transaction(function () use ($request, $organization, $project, $data, $creator, $operations, $projects): ServiceTicket {
            $ticket = $creator->create(
                $organization,
                $request->user(),
                $data,
                $request->boolean('create_visit'),
                $request->boolean('confirm_conflicts'),
            );
            $projects->linkTicket(
                $project,
                $operations->resolve($organization, $ticket->id),
                $request->user(),
                $request->boolean('confirm_location_mismatch'),
            );

            return $ticket;
        });

        return redirect()->route('office.service-tickets.show', $ticket)
            ->with('status', "Service Ticket created from {$project->project_number} — {$project->name}.");
    }

    private function authorize(Organization $organization, Project $project): void
    {
        Gate::authorize('administer', $project);
        Gate::authorize('create', [ServiceTicket::class, $organization]);
    }

    private function requireCustomer(Project $project): void
    {
        if ($project->customer_id === null) {
            throw ValidationException::withMessages(['project' => 'A customer-backed Project is required to create a Service Ticket.']);
        }
    }

    private function scoped(Organization $organization, Project $project): Project
    {
        abort_unless($project->organization_id === $organization->id, 404);

        return $project;
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function fieldMemberships(Organization $organization): Collection
    {
        return $organization->memberships()->with(['user', 'roles.capabilities', 'capabilityOverrides'])
            ->where('status', 'active')->get()
            ->filter(fn ($membership) => $membership->hasCapability('experience.field.access'))
            ->sortBy(fn ($membership) => $membership->user->name)->values();
    }

    private function options(): array
    {
        return [
            'priorities' => config('service_tickets.priorities'),
            'sources' => config('service_tickets.sources'),
            'purposes' => config('service_tickets.purposes'),
            'billingDispositions' => config('service_tickets.billing_dispositions'),
        ];
    }
}
