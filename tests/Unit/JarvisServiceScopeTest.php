<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the token-ability scoping primitive that future scoped endpoints
 * (OP-API-2+) will rely on, ahead of any resource route existing.
 */
class JarvisServiceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_can_reports_true_only_for_granted_abilities(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create(['status' => 'service_account']);
        $token = $user->createToken('jarvis-core', ['tickets.read', 'customers.read']);
        $user->withAccessToken($token->accessToken);

        $this->assertTrue($user->tokenCan('tickets.read'));
        $this->assertTrue($user->tokenCan('customers.read'));
        $this->assertFalse($user->tokenCan('tickets.create'));
        $this->assertFalse($user->tokenCan('users.manage'));
    }

    public function test_jarvis_service_role_grants_exactly_the_planned_api_scopes(): void
    {
        $this->seed(AccessControlSeeder::class);
        $organization = Organization::query()->create([
            'name' => 'NewDay Tech LLC', 'slug' => 'newday-tech', 'timezone' => 'America/Chicago', 'active' => true,
        ]);
        $user = User::factory()->create(['status' => 'service_account']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());

        $expected = [
            'api.customers.read', 'api.contacts.read', 'api.locations.read', 'api.projects.read',
            'api.tickets.read', 'api.tickets.create', 'api.tickets.update', 'api.communications.create',
        ];
        foreach ($expected as $capability) {
            $this->assertTrue($membership->hasCapability($capability), "Expected jarvis_service to grant {$capability}");
        }

        $this->assertFalse($membership->hasCapability('users.manage'));
        $this->assertFalse($membership->hasCapability('customers.manage'));
        $this->assertFalse($membership->hasCapability('dispatch.manage'));
    }
}
