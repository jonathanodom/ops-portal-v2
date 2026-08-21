<?php

namespace Tests\Feature;

use App\Domain\Home\Queries\CustomerDirectorySearchQuery;
use App\Domain\Projects\Queries\ProjectHomeSummaryQuery;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\NewDayHomeSnapshot;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NewDayHomeTest extends TestCase
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

    public function test_projects_home_summary_uses_organization_local_date_and_excludes_terminal_and_foreign_records(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $project = $this->project($organization, 'NDT-PRJ-2026-0001', 'Visible Project', 'active');

        $this->task($project, 'Due locally today', 'in_progress', '2026-08-13');
        $this->task($project, 'Overdue', 'in_progress', '2026-08-12');
        $this->task($project, 'Blocked', 'blocked', '2026-08-20');
        $this->task($project, 'Done old task', 'done', '2026-08-01');
        $this->task($project, 'Canceled old task', 'canceled', '2026-08-01');
        $this->milestone($project, 'Upcoming', 'planned', '2026-09-01');
        $this->milestone($project, 'Completed', 'completed', '2026-08-20');
        $this->milestone($project, 'Canceled', 'canceled', '2026-08-20');

        $other = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $foreign = $this->project($other, 'NDT-PRJ-2026-0001', 'Hidden Project', 'active');
        $this->task($foreign, 'Hidden overdue', 'blocked', '2026-08-01');
        $this->milestone($foreign, 'Hidden milestone', 'planned', '2026-08-20');

        $summary = app(ProjectHomeSummaryQuery::class)->for($organization);

        $this->assertSame([
            'active' => 1,
            'due_today' => 1,
            'overdue' => 1,
            'blocked' => 1,
            'upcoming_milestones' => 1,
        ], $summary['counts']);
        $this->assertSame(['Overdue'], $summary['overdue_tasks']->pluck('title')->all());
        $this->assertSame(['Blocked'], $summary['blocked_tasks']->pluck('title')->all());
        $this->assertSame(['Upcoming'], $summary['upcoming_milestones']->pluck('name')->all());
    }

    public function test_home_composes_a_bounded_deterministic_project_attention_feed_with_authoritative_links(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [, $membership] = $this->userWithRole('super_admin', $organization);
        $project = $this->project($organization, 'NDT-PRJ-2026-0042', 'Home Project', 'active');

        foreach (range(1, 8) as $index) {
            $this->task($project, "Overdue {$index}", 'in_progress', '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $this->task($project, "Blocked {$index}", 'blocked', '2026-09-01');
            $this->milestone($project, "Milestone {$index}", 'planned', '2026-08-'.str_pad((string) (14 + $index), 2, '0', STR_PAD_LEFT));
        }

        $home = app(NewDayHomeSnapshot::class)->for($organization, $membership);

        $this->assertCount(12, $home['attention_items']);
        $this->assertSame(array_fill(0, 6, 'overdue_task'), $home['attention_items']->take(6)->pluck('kind')->all());
        $this->assertSame(array_fill(0, 6, 'blocked_task'), $home['attention_items']->skip(6)->pluck('kind')->all());
        $this->assertTrue($home['attention_items']->every(fn (array $item) => str_contains($item['route'], "/office/projects/{$project->id}")));
        $this->assertSame(['Service Operations', 'Projects'], collect($home['launchers'])->pluck('label')->all());
    }

    public function test_home_skips_projects_and_directory_search_when_capabilities_are_denied(): void
    {
        $organization = Organization::factory()->create();
        [$user, $membership] = $this->userWithRole('super_admin', $organization);
        $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'projects.view')->firstOrFail(), ['effect' => 'deny']);
        $membership->capabilityOverrides()->attach(Capability::query()->where('key', 'customers.view')->firstOrFail(), ['effect' => 'deny']);

        $home = app(NewDayHomeSnapshot::class)->for($organization, $membership->fresh());

        $this->assertNull($home['projects']);
        $this->assertFalse($home['search_visible']);
        $this->assertNotContains('Projects', collect($home['launchers'])->pluck('label'));
        $this->actingAs($user)->get('/office')
            ->assertOk()
            ->assertDontSee('data-home-projects', false)
            ->assertDontSee('data-home-directory-search', false);
        $this->actingAs($user)->get('/office/search?q=Acme')->assertForbidden();
    }

    public function test_customer_directory_search_is_bounded_wildcard_safe_and_organization_scoped(): void
    {
        $organization = Organization::factory()->create();
        $customer = Customer::factory()->create([
            'organization_id' => $organization->id,
            'display_name' => 'Acme Technology',
            'legal_name' => 'Acme Holdings LLC',
            'phone' => '(817) 555-0199',
            'phone_normalized' => '8175550199',
            'email' => 'office@acme.test',
            'status' => 'active',
        ]);
        Contact::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Jordan Smith',
            'role' => 'Facilities Director',
            'phone' => '(817) 555-0123',
            'phone_normalized' => '8175550123',
            'email' => 'jordan@acme.test',
            'active' => false,
        ]);
        ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Acme Warehouse',
            'address_line_1' => '123 Commerce Street',
            'city' => 'Fort Worth',
            'state' => 'TX',
            'postal_code' => '76102',
            'timezone' => 'America/Chicago',
            'active' => false,
        ]);

        $other = Organization::factory()->create();
        Customer::factory()->create(['organization_id' => $other->id, 'display_name' => 'Acme Hidden']);
        foreach (range(1, 12) as $index) {
            Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => "Bounded {$index}"]);
        }

        $search = app(CustomerDirectorySearchQuery::class);
        $this->assertFalse($search->search($organization, ' a ')['searched']);
        $this->assertSame([$customer->id], $search->search($organization, 'Acme Technology')['customers']->modelKeys());
        $this->assertSame([$customer->id], $search->search($organization, 'Holdings')['customers']->modelKeys());
        $this->assertSame([$customer->id], $search->search($organization, '817-555-0199')['customers']->modelKeys());
        $this->assertSame([$customer->id], $search->search($organization, 'office@acme.test')['customers']->modelKeys());
        $this->assertSame('Jordan Smith', $search->search($organization, 'Facilities Director')['contacts']->first()->name);
        $this->assertSame('Jordan Smith', $search->search($organization, '817-555-0123')['contacts']->first()->name);
        $this->assertSame('Acme Technology', $search->search($organization, 'jordan@acme.test')['contacts']->first()->customer->display_name);
        $this->assertSame('Acme Technology', $search->search($organization, 'Commerce Street')['locations']->first()->customer->display_name);
        $this->assertSame('Acme Warehouse', $search->search($organization, 'Fort Worth')['locations']->first()->name);
        $this->assertSame('Acme Warehouse', $search->search($organization, '76102')['locations']->first()->name);
        $this->assertCount(CustomerDirectorySearchQuery::LIMIT_PER_GROUP, $search->search($organization, 'Bounded')['customers']);
        $this->assertTrue($search->search($organization, '%_')['customers']->isEmpty());
        $this->assertNotContains('Acme Hidden', $search->search($organization, 'Acme')['customers']->pluck('display_name'));
    }

    public function test_directory_results_render_grouped_links_and_inactive_status(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole('super_admin', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Northstar Client']);
        $contact = Contact::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Northstar Contact',
            'active' => false,
        ]);
        $location = ServiceLocation::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Northstar Location',
            'address_line_1' => '100 Main Street',
            'city' => 'Dallas',
            'state' => 'TX',
            'postal_code' => '75201',
            'timezone' => 'America/Chicago',
            'active' => false,
        ]);

        $this->actingAs($user)->get('/office/search?q=Northstar')
            ->assertOk()
            ->assertSee('Customers')
            ->assertSee('Contacts')
            ->assertSee('Service Locations')
            ->assertSee(route('office.customers.show', $customer), false)
            ->assertSee(route('office.customers.show', $customer).'#contacts', false)
            ->assertSee(route('office.locations.show', $location), false)
            ->assertSee($contact->name)
            ->assertSee('Inactive');

        $this->actingAs($user)->get('/office/search?q=x')->assertOk()->assertSee('Enter at least two characters');
        $this->actingAs($user)->get('/office/search?q=NoSuchDirectoryRecord')->assertOk()->assertSee('No directory records found');
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

    private function project(Organization $organization, string $number, string $name, string $status): Project
    {
        return Project::query()->create([
            'organization_id' => $organization->id,
            'project_number' => $number,
            'name' => $name,
            'type' => 'installation_project',
            'status' => $status,
        ]);
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

    private function milestone(Project $project, string $name, string $status, string $targetOn): ProjectMilestone
    {
        return ProjectMilestone::query()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => $name,
            'status' => $status,
            'target_on' => $targetOn,
        ]);
    }
}
