<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeCollapsibleNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_office_shell_renders_an_expanded_accessible_collapsible_navigation(): void
    {
        $organization = Organization::factory()->create();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->get(route('office.home'))
            ->assertOk()
            ->assertSee('data-office-sidebar-state="expanded"', false)
            ->assertSee('data-office-sidebar-key="ndt:office-sidebar:'.$admin->id.':'.$organization->id.'"', false)
            ->assertSee('data-office-nav-groups-key="ndt:office-nav-groups:'.$admin->id.':'.$organization->id.'"', false)
            ->assertSee('id="office-sidebar"', false)
            ->assertSee('data-office-shell-grid', false)
            ->assertSee('data-office-sidebar-toggle', false)
            ->assertSee('aria-controls="office-sidebar"', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('aria-label="Collapse office navigation"', false)
            ->assertSee('data-office-nav-icon', false)
            ->assertSee('data-office-tooltip="Home"', false)
            ->assertSee('data-office-tooltip="Sign out"', false)
            ->assertSee('aria-label="Office mobile"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_desktop_navigation_renders_accessible_groups_and_opens_the_active_group(): void
    {
        $organization = Organization::factory()->create();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $response = $this->actingAs($admin)->get(route('office.dispatch.index'));

        $response->assertOk()
            ->assertSee('data-office-nav-group="sales"', false)
            ->assertSee('data-office-nav-group="service" data-office-nav-group-active="true"', false)
            ->assertSee('aria-controls="office-nav-group-service"', false)
            ->assertSee('aria-haspopup="true"', false)
            ->assertSee('data-office-tooltip="Service"', false)
            ->assertSee('class="office-nav-flyout-title hidden', false)
            ->assertSee('id="office-nav-group-service"', false)
            ->assertSee('data-office-nav-key="dispatch"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_collapsed_flyout_markup_reuses_only_authorized_group_children(): void
    {
        $organization = Organization::factory()->create();
        [$reviewer, $reviewerMembership] = $this->userWithRole('reviewer', $organization);
        $reviewerMembership->capabilityOverrides()->attach(
            Capability::query()->where('key', 'service_tickets.view')->firstOrFail(),
            ['effect' => 'deny'],
        );

        $this->actingAs($reviewer)->get(route('office.home'))
            ->assertOk()
            ->assertSee('aria-label="Service navigation"', false)
            ->assertSee('data-office-nav-key="review"', false)
            ->assertDontSee('data-office-nav-key="dispatch"', false)
            ->assertDontSee('data-office-nav-group="operations"', false);
    }

    public function test_navigation_preference_key_is_isolated_by_user_and_organization(): void
    {
        $firstOrganization = Organization::factory()->create();
        $secondOrganization = Organization::factory()->create();
        [$firstUser] = $this->userWithRole('reviewer', $firstOrganization);
        [$secondUser] = $this->userWithRole('reviewer', $secondOrganization);

        $this->actingAs($firstUser)->get(route('office.home'))
            ->assertSee('data-office-sidebar-key="ndt:office-sidebar:'.$firstUser->id.':'.$firstOrganization->id.'"', false)
            ->assertDontSee('data-office-sidebar-key="ndt:office-sidebar:'.$secondUser->id.':'.$secondOrganization->id.'"', false);

        $this->actingAs($secondUser)->get(route('office.home'))
            ->assertSee('data-office-sidebar-key="ndt:office-sidebar:'.$secondUser->id.':'.$secondOrganization->id.'"', false)
            ->assertDontSee('data-office-sidebar-key="ndt:office-sidebar:'.$firstUser->id.':'.$firstOrganization->id.'"', false);
    }

    public function test_secondary_catalog_routes_still_open_the_catalog_group(): void
    {
        $organization = Organization::factory()->create();
        [$admin] = $this->userWithRole('super_admin', $organization);

        $this->actingAs($admin)->get(route('office.catalog.units.index'))
            ->assertOk()
            ->assertSee('data-office-nav-group="catalog" data-office-nav-group-active="true"', false)
            ->assertSee('aria-controls="office-nav-group-catalog"', false);
    }

    public function test_collapsible_navigation_preserves_capability_gated_destinations(): void
    {
        $organization = Organization::factory()->create();
        [$reviewer] = $this->userWithRole('reviewer', $organization);

        $this->actingAs($reviewer)->get(route('office.home'))
            ->assertOk()
            ->assertSee('data-office-nav-key="customers"', false)
            ->assertSee('data-office-nav-key="projects"', false)
            ->assertSee('data-office-nav-key="review"', false)
            ->assertSee('data-office-nav-group="service"', false)
            ->assertDontSee('data-office-nav-key="health"', false)
            ->assertDontSee('data-office-nav-key="archive"', false)
            ->assertDontSee('data-office-nav-group="operations"', false)
            ->assertDontSee('data-office-nav-key="settings"', false);
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
}
