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

class AccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_without_technician_profile_can_open_both_experiences(): void
    {
        [$user, $membership] = $this->userWithRole('super_admin');

        $this->assertNull($user->technicianProfile);
        $this->actingAs($user)->get('/office')->assertOk()->assertSee('Customer operations');
        $this->actingAs($user)->get('/field')->assertOk()->assertSee('No visits today');
        $this->assertFalse($membership->hasCapability('visits.execute_any'));
    }

    public function test_role_access_matrix_is_enforced_server_side(): void
    {
        [$technician] = $this->userWithRole('technician');
        [$reviewer] = $this->userWithRole('reviewer');
        [$billing] = $this->userWithRole('billing');

        $this->actingAs($technician)->get('/field')->assertOk();
        $this->actingAs($technician)->get('/office')->assertForbidden();
        $this->actingAs($reviewer)->get('/office')->assertOk();
        $this->actingAs($reviewer)->get('/field')->assertForbidden();
        $this->actingAs($billing)->get('/office')->assertOk();
        $this->actingAs($billing)->get('/field')->assertForbidden();
    }

    public function test_inactive_membership_is_denied_and_query_input_cannot_change_organization(): void
    {
        [$user, $membership, $organization] = $this->userWithRole('super_admin');
        $other = Organization::query()->create([
            'name' => 'Other Organization',
            'slug' => 'other',
            'timezone' => 'UTC',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get('/office?organization_id='.$other->id)
            ->assertOk()
            ->assertSee($organization->name)
            ->assertDontSee($other->name);

        $membership->update(['status' => 'inactive']);
        $this->actingAs($user)->get('/office')->assertForbidden();
    }

    public function test_explicit_capability_override_can_grant_and_deny_access(): void
    {
        [$user, $membership] = $this->userWithRole('super_admin');
        $executeAny = Capability::query()->where('key', 'visits.execute_any')->firstOrFail();
        $office = Capability::query()->where('key', 'experience.office.access')->firstOrFail();

        $membership->capabilityOverrides()->attach($executeAny, ['effect' => 'grant']);
        $this->assertTrue($membership->hasCapability('visits.execute_any'));

        $membership->capabilityOverrides()->attach($office, ['effect' => 'deny']);
        $this->actingAs($user)->get('/office')->assertForbidden();
    }

    public function test_home_redirects_to_first_authorized_experience(): void
    {
        [$technician] = $this->userWithRole('technician');
        [$reviewer] = $this->userWithRole('reviewer');

        $this->actingAs($technician)->get('/')->assertRedirect('/field');
        $this->actingAs($reviewer)->get('/')->assertRedirect('/office');
    }

    /**
     * @return array{User, OrganizationMembership, Organization}
     */
    private function userWithRole(string $roleKey): array
    {
        $organization = Organization::query()->create([
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'timezone' => 'America/Chicago',
            'active' => true,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $membership, $organization];
    }
}
