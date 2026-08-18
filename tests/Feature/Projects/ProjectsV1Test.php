<?php

namespace Tests\Feature\Projects;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\ProjectWorkstream;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectsV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-16 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_finite_and_ongoing_projects_use_scoped_immutable_numbers(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer] = $this->customerLocation($organization, 'Trip Hopper');

        $this->actingAs($user)->post(route('office.projects.store'), [
            'name' => 'Trip Hopper — IT Support', 'type' => 'ongoing_support', 'status' => 'active', 'customer_id' => $customer->id,
        ])->assertRedirect();
        $this->actingAs($user)->post(route('office.projects.store'), [
            'name' => 'Network Upgrade', 'type' => 'installation_project', 'status' => 'planning', 'customer_id' => $customer->id,
            'start_on' => '2026-08-16', 'target_end_on' => '2026-09-30',
        ])->assertRedirect();

        $this->assertSame(['NDT-PRJ-2026-0001', 'NDT-PRJ-2026-0002'], Project::query()->orderBy('id')->pluck('project_number')->all());
        $this->assertNull(Project::query()->firstOrFail()->target_end_on);
        $this->assertDatabaseHas('document_sequences', ['organization_id' => $organization->id, 'document_type' => 'project', 'year' => 2026, 'current_value' => 2]);
    }

    public function test_non_internal_project_requires_customer_and_context_is_organization_scoped(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$foreignCustomer, $foreignLocation] = $this->customerLocation($other, 'Foreign');

        $this->actingAs($user)->post(route('office.projects.store'), ['name' => 'Missing', 'type' => 'ongoing_support', 'status' => 'active'])
            ->assertSessionHasErrors('customer_id');
        $this->actingAs($user)->post(route('office.projects.store'), ['name' => 'Forged', 'type' => 'installation_project', 'status' => 'planning', 'customer_id' => $foreignCustomer->id, 'service_location_id' => $foreignLocation->id])
            ->assertNotFound();
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_internal_project_may_have_no_customer(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        $this->actingAs($user)->post(route('office.projects.store'), ['name' => 'Internal Operations', 'type' => 'internal', 'status' => 'planning'])->assertRedirect();
        $this->assertDatabaseHas('projects', ['organization_id' => $organization->id, 'customer_id' => null, 'type' => 'internal']);
    }

    public function test_task_lifecycle_assignment_blocking_completion_reopen_and_local_overdue(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->userWithRole($organization, 'dispatcher');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'type' => 'internal', 'status' => 'active']);

        $this->actingAs($user)->post(route('office.projects.tasks.store', $project), ['title' => 'Blocked', 'status' => 'blocked', 'priority' => 'high'])->assertSessionHasErrors('blocked_reason');
        $this->actingAs($user)->post(route('office.projects.tasks.store', $project), ['title' => 'Install', 'status' => 'planned', 'priority' => 'normal', 'assigned_to_user_id' => $user->id, 'due_on' => '2026-08-15'])->assertRedirect();
        $task = ProjectTask::query()->firstOrFail();
        $this->assertTrue(app(ProjectWorkflow::class)->isOverdue($task, $organization));

        $payload = ['title' => 'Install', 'description' => '', 'status' => 'done', 'priority' => 'normal', 'assigned_to_user_id' => $user->id, 'due_on' => '2026-08-15'];
        $this->actingAs($user)->put(route('office.projects.tasks.update', [$project, $task]), $payload)->assertRedirect();
        $this->assertNotNull($task->fresh()->completed_at);
        $this->actingAs($user)->put(route('office.projects.tasks.update', [$project, $task]), [...$payload, 'status' => 'in_progress'])->assertRedirect();
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_completed_and_canceled_projects_reject_operational_changes(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        foreach (['completed', 'canceled'] as $status) {
            $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => $status]);
            $this->actingAs($user)->post(route('office.projects.tasks.store', $project), ['title' => 'No', 'status' => 'planned', 'priority' => 'normal'])->assertSessionHasErrors('project');
        }
    }

    public function test_workstreams_milestones_notes_and_safe_activity_render(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $this->actingAs($user)->post(route('office.projects.workstreams.store', $project), ['name' => 'Networking', 'status' => 'active'])->assertRedirect();
        $this->actingAs($user)->post(route('office.projects.milestones.store', $project), ['name' => 'Cutover', 'status' => 'planned', 'target_on' => '2026-09-01'])->assertRedirect();
        $this->actingAs($user)->post(route('office.projects.notes.store', $project), ['type' => 'decision', 'body' => 'Private customer detail'])->assertRedirect();
        $this->assertDatabaseHas('project_notes', ['project_id' => $project->id, 'body' => 'Private customer detail']);
        $metadata = AuditEvent::query()->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->get()->pluck('metadata')->toJson();
        $this->assertStringNotContainsString('Private customer detail', $metadata);
        $this->actingAs($user)->get(route('office.projects.show', $project))->assertOk()->assertSee('Networking')->assertSee('Cutover')->assertSee('Private customer detail')->assertSee('Notes / Activity');
    }

    public function test_project_detail_forms_use_padded_and_inset_treatments(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer] = $this->customerLocation($organization, 'Form Spacing Customer');
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'status' => 'active',
        ]);
        ProjectWorkstream::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Delivery',
            'status' => 'active',
        ]);
        ProjectTask::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'title' => 'Verify spacing',
            'status' => 'planned',
            'priority' => 'normal',
        ]);
        ProjectMilestone::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'name' => 'Review',
            'status' => 'planned',
        ]);

        $response = $this->actingAs($user)->get(route('office.projects.show', $project))->assertOk();

        $response
            ->assertSee('office-detail-form-body office-detail-form-separated office-detail-form-grid', false)
            ->assertSee('office-detail-form-inset office-detail-form-grid', false)
            ->assertSee('class="form-textarea"', false)
            ->assertSee('office-detail-form-inset', false)
            ->assertSee('Edit Project');
    }

    public function test_project_workstream_and_milestone_status_corrections_preserve_history(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->userWithRole($organization, 'dispatcher');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'type' => 'internal', 'status' => 'active']);

        $this->actingAs($user)->put(route('office.projects.update', $project), [
            'name' => $project->name, 'type' => $project->type, 'status' => 'completed',
        ])->assertRedirect();
        $this->assertNotNull($project->fresh()->completed_at);
        $this->actingAs($user)->put(route('office.projects.update', $project), [
            'name' => $project->name, 'type' => $project->type, 'status' => 'active',
        ])->assertRedirect();
        $this->assertNull($project->fresh()->completed_at);

        $workstream = ProjectWorkstream::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Network', 'status' => 'planned',
        ]);
        $this->actingAs($user)->put(route('office.projects.workstreams.update', [$project, $workstream]), [
            'name' => 'Network', 'status' => 'completed',
        ])->assertRedirect();
        $this->assertSame('completed', $workstream->fresh()->status);

        $milestone = ProjectMilestone::query()->create([
            'organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Cutover', 'status' => 'planned',
        ]);
        $this->actingAs($user)->put(route('office.projects.milestones.update', [$project, $milestone]), [
            'name' => 'Cutover', 'status' => 'completed',
        ])->assertRedirect();
        $this->assertSame('2026-08-16', $milestone->fresh()->completed_on->toDateString());
        $this->actingAs($user)->put(route('office.projects.milestones.update', [$project, $milestone]), [
            'name' => 'Cutover', 'status' => 'in_progress',
        ])->assertRedirect();
        $this->assertNull($milestone->fresh()->completed_on);
    }

    public function test_ticket_link_requires_same_customer_and_confirmation_for_location_mismatch(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization, 'ABC Dental');
        $otherLocation = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        [$otherCustomer, $otherCustomerLocation] = $this->customerLocation($organization, 'Other');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'status' => 'active']);
        $same = $this->ticket($organization, $customer, $location, 'Same');
        $differentLocation = $this->ticket($organization, $customer, $otherLocation, 'Different location');
        $differentCustomer = $this->ticket($organization, $otherCustomer, $otherCustomerLocation, 'Different customer');

        $this->actingAs($user)->post(route('office.projects.tickets.link', $project), ['service_ticket_id' => $same->id])->assertRedirect();
        $this->actingAs($user)->post(route('office.projects.tickets.link', $project), ['service_ticket_id' => $differentLocation->id])->assertSessionHasErrors('confirm_location_mismatch');
        $this->actingAs($user)->post(route('office.projects.tickets.link', $project), ['service_ticket_id' => $differentLocation->id, 'confirm_location_mismatch' => '1'])->assertRedirect();
        $this->actingAs($user)->post(route('office.projects.tickets.link', $project), ['service_ticket_id' => $differentCustomer->id])->assertSessionHasErrors('service_ticket_id');
        $this->actingAs($user)->get(route('office.projects.show', $project))->assertOk()->assertSee('Different project location');
    }

    public function test_directory_contracts_return_scoped_immutable_projections(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [$customer, $location] = $this->customerLocation($organization, 'Scoped');
        [$foreign] = $this->customerLocation($other, 'Foreign');
        $ticket = $this->ticket($organization, $customer, $location, 'Scoped ticket');
        $customers = app(CustomerDirectory::class);
        $operations = app(ServiceOperationsDirectory::class);
        $this->assertSame('Scoped', $customers->resolve($organization, $customer->id)->displayName);
        $this->assertSame('Scoped ticket', $operations->resolve($organization, $ticket->id)->title);
        $this->expectException(ModelNotFoundException::class);
        $customers->resolve($organization, $foreign->id);
    }

    public function test_seeded_roles_and_explicit_denial_follow_capability_matrix(): void
    {
        $organization = Organization::factory()->create();
        [$dispatcher, $dispatcherMembership] = $this->userWithRole($organization, 'dispatcher');
        [$reviewer, $reviewerMembership] = $this->userWithRole($organization, 'reviewer');
        [$technician] = $this->userWithRole($organization, 'technician');
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $this->actingAs($dispatcher)->get(route('office.projects.create'))->assertOk();
        $this->actingAs($reviewer)->get(route('office.projects.show', $project))->assertOk()->assertDontSee('Edit Project');
        $this->actingAs($technician)->get(route('office.projects.index'))->assertForbidden();
        $capability = Capability::query()->where('key', 'projects.view')->firstOrFail();
        $dispatcherMembership->capabilityOverrides()->attach($capability->id, ['effect' => 'deny']);
        $this->actingAs($dispatcher)->get(route('office.projects.index'))->assertForbidden();
        $reviewerMembership->update(['status' => 'inactive']);
        $this->actingAs($reviewer)->get(route('office.projects.index'))->assertForbidden();
    }

    public function test_tasks_capability_can_be_granted_without_project_or_relationship_administration(): void
    {
        $organization = Organization::factory()->create();
        [$reviewer, $membership] = $this->userWithRole($organization, 'reviewer');
        $membership->capabilityOverrides()->attach(
            Capability::query()->where('key', 'projects.tasks.manage')->firstOrFail(),
            ['effect' => 'grant'],
        );
        $project = Project::factory()->create(['organization_id' => $organization->id, 'type' => 'internal', 'status' => 'active']);

        $this->actingAs($reviewer)->post(route('office.projects.tasks.store', $project), [
            'title' => 'Follow up', 'status' => 'planned', 'priority' => 'normal',
        ])->assertRedirect();
        $this->actingAs($reviewer)->post(route('office.projects.notes.store', $project), [
            'type' => 'note', 'body' => 'Internal note',
        ])->assertRedirect();
        $this->actingAs($reviewer)->put(route('office.projects.update', $project), [
            'name' => 'Unauthorized', 'type' => 'internal', 'status' => 'active',
        ])->assertForbidden();
        $this->actingAs($reviewer)->post(route('office.projects.tickets.link', $project), [
            'service_ticket_id' => 1,
        ])->assertForbidden();
    }

    public function test_cross_organization_project_and_nested_records_return_not_found(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        $project = Project::factory()->create(['organization_id' => $other->id, 'status' => 'active']);
        $this->actingAs($user)->get(route('office.projects.show', $project))->assertNotFound();
    }

    private function userWithRole(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$user, $membership];
    }

    private function customerLocation(Organization $organization, string $name): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => $name]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);

        return [$customer, $location];
    }

    private function ticket(Organization $organization, Customer $customer, ServiceLocation $location, string $title): ServiceTicket
    {
        return ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-'.fake()->unique()->numberBetween(1000, 9999), 'title' => $title, 'priority' => 'normal', 'source' => 'internal', 'purpose' => 'service_call', 'billing_disposition' => 'billable', 'status' => 'open']);
    }
}
