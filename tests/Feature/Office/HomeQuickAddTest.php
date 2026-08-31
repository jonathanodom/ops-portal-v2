<?php

namespace Tests\Feature\Office;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeQuickAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_authorized_home_quick_add_links_to_existing_ticket_and_manual_lead_routes(): void
    {
        [, $admin] = $this->organizationMember('super_admin');

        $this->actingAs($admin)->get(route('office.home'))
            ->assertOk()
            ->assertSee('data-home-quick-add', false)
            ->assertSee('+ Quick Add')
            ->assertSee('New Service Ticket')
            ->assertSee('href="'.route('office.service-tickets.create').'"', false)
            ->assertSee('New Lead')
            ->assertSee('href="'.route('office.leads.create').'"', false);

        $this->actingAs($admin)->get(route('office.service-tickets.create'))->assertOk();
        $this->actingAs($admin)->get(route('office.leads.create'))->assertOk();
    }

    public function test_home_hides_quick_add_when_membership_has_neither_creation_capability(): void
    {
        [, $reviewer] = $this->organizationMember('reviewer');

        $this->actingAs($reviewer)->get(route('office.home'))
            ->assertOk()
            ->assertDontSee('data-home-quick-add', false)
            ->assertDontSee('New Lead')
            ->assertDontSee('New Service Ticket');
    }

    private function organizationMember(string $role): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$organization, $user, $membership];
    }
}
