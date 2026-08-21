<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerProjectHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-14 03:30:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_detail_shows_scoped_projects_with_local_task_counts_and_deterministic_order(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        $owner = User::factory()->create(['name' => 'Project Owner']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Visible Customer']);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Main Campus',
        ]);

        $planning = $this->project($organization, $customer, [
            'project_number' => 'NDT-PRJ-2026-0002',
            'name' => 'Planning Project',
            'status' => 'planning',
            'updated_at' => '2026-08-14 02:00:00',
        ]);
        $active = $this->project($organization, $customer, [
            'project_number' => 'NDT-PRJ-2026-0001',
            'name' => 'Network Upgrade',
            'type' => 'installation_project',
            'status' => 'active',
            'owner_user_id' => $owner->id,
            'service_location_id' => $location->id,
            'target_end_on' => '2026-09-30',
            'updated_at' => '2026-08-01 12:00:00',
        ]);
        $this->task($active, 'Open overdue', 'in_progress', '2026-08-12');
        $this->task($active, 'Blocked future', 'blocked', '2026-08-20');
        $this->task($active, 'Completed old', 'done', '2026-08-01');
        $this->task($active, 'Canceled old', 'canceled', '2026-08-01');

        $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Other Customer']);
        $this->project($organization, $otherCustomer, ['project_number' => 'NDT-PRJ-2026-0098', 'name' => 'Other Customer Project']);
        $otherOrganization = Organization::factory()->create();
        $this->project($otherOrganization, $customer, ['project_number' => 'NDT-PRJ-2026-0099', 'name' => 'Foreign Organization Project']);

        $response = $this->actingAs($admin)->get(route('office.customers.show', $customer));
        $response->assertOk()
            ->assertSee('href="#projects"', false)
            ->assertSee('id="projects"', false)
            ->assertSee($active->project_number)
            ->assertSee($active->name)
            ->assertSee('Installation Project')
            ->assertSee('Active')
            ->assertSee($owner->name)
            ->assertSee($location->name)
            ->assertSee('Target Sep 30, 2026')
            ->assertSee('2 open')
            ->assertSee('1 overdue')
            ->assertSee('1 blocked')
            ->assertSee(route('office.projects.show', $active), false)
            ->assertSee('Customer-wide / multi-site')
            ->assertSee('Owner: Unassigned')
            ->assertSee('New Project')
            ->assertSee(route('office.projects.create', ['customer_id' => $customer->id]), false)
            ->assertDontSee('Other Customer Project')
            ->assertDontSee('Foreign Organization Project')
            ->assertSee('Service ticket history')
            ->assertSee('Invoice history')
            ->assertSee('Recurring customer Services');

        $content = $response->getContent();
        $this->assertLessThan(strpos($content, $planning->project_number), strpos($content, $active->project_number));
        $this->assertSame(1, substr_count($content, 'id="projects"'));
    }

    public function test_projects_view_without_manage_shows_history_but_hides_new_project_action(): void
    {
        $organization = Organization::factory()->create();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $project = $this->project($organization, $customer, ['name' => 'Read-only Project']);

        $this->actingAs($reviewer)->get(route('office.customers.show', $customer))
            ->assertOk()
            ->assertSee('id="projects"', false)
            ->assertSee($project->name)
            ->assertDontSee('New Project');
    }

    public function test_projects_capability_denial_hides_navigation_section_and_skips_project_query(): void
    {
        $organization = Organization::factory()->create();
        [$admin, $membership] = $this->userWithRole('super_admin', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $this->project($organization, $customer, ['name' => 'Hidden Project']);
        $membership->capabilityOverrides()->attach(
            Capability::query()->where('key', 'projects.view')->firstOrFail(),
            ['effect' => 'deny'],
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('office.customers.show', $customer));
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query) => strtolower($query));
        DB::disableQueryLog();

        $response->assertOk()
            ->assertDontSee('href="#projects"', false)
            ->assertDontSee('id="projects"', false)
            ->assertDontSee('Hidden Project')
            ->assertSee('Service ticket history')
            ->assertSee('Invoice history')
            ->assertSee('Recurring customer Services');
        $this->assertFalse($queries->contains(fn (string $query) => str_contains($query, 'from "projects"') || str_contains($query, 'from `projects`')));
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership->fresh()];
    }

    private function project(Organization $organization, Customer $customer, array $attributes = []): Project
    {
        return Project::query()->create(array_merge([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'project_number' => 'NDT-PRJ-2026-'.str_pad((string) (Project::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'name' => 'Customer Project',
            'type' => 'ongoing_support',
            'status' => 'active',
        ], $attributes));
    }

    private function task(Project $project, string $title, string $status, string $dueOn): ProjectTask
    {
        return ProjectTask::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'title' => $title,
            'status' => $status,
            'priority' => 'normal',
            'due_on' => $dueOn,
        ]);
    }
}
