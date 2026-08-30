<?php

namespace Tests\Feature;

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

    public function test_collapsible_navigation_preserves_capability_gated_destinations(): void
    {
        $organization = Organization::factory()->create();
        [$reviewer] = $this->userWithRole('reviewer', $organization);

        $this->actingAs($reviewer)->get(route('office.home'))
            ->assertOk()
            ->assertSee('data-office-nav-key="customers"', false)
            ->assertSee('data-office-nav-key="projects"', false)
            ->assertSee('data-office-nav-key="review"', false)
            ->assertDontSee('data-office-nav-key="health"', false)
            ->assertDontSee('data-office-nav-key="archive"', false)
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
