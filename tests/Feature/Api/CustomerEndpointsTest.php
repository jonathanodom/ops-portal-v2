<?php

namespace Tests\Feature\Api;

use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_search_returns_matching_customers_within_the_organization(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create(['display_name' => 'T&S Communications']);
        Customer::factory()->for($organization)->create(['display_name' => 'Unrelated Co']);

        $response = $this->bearer($token)->getJson('/api/v1/customers/search?q=T%26S');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $customer->id)
            ->assertJsonPath('data.0.name', 'T&S Communications')
            ->assertJsonPath('data.0.primary_phone', $customer->phone)
            ->assertJsonStructure(['data' => [['id', 'name', 'status', 'primary_phone', 'primary_email']], 'meta' => ['request_id']]);
    }

    public function test_search_only_returns_customers_within_the_authenticated_organization(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        Customer::factory()->for($other)->create(['display_name' => 'Cross Org Customer']);

        $response = $this->bearer($token)->getJson('/api/v1/customers/search?q=Cross');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_requires_a_query_parameter(): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->bearer($token)->getJson('/api/v1/customers/search');

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.q.0', 'The q field is required.');
    }

    public function test_show_returns_the_customer_summary(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk()->assertJsonPath('data.id', (string) $customer->id);
    }

    public function test_show_returns_not_found_for_a_customer_in_another_organization(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}");

        $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_not_found_response_never_leaks_the_internal_model_class_name(): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->bearer($token)->getJson('/api/v1/customers/999999');

        $response->assertStatus(404)
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertDontSeeText('App\\Models\\Customer');
    }

    public function test_locations_endpoint_lists_service_locations_for_the_customer(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $location = ServiceLocation::factory()->for($customer)->create(['organization_id' => $organization->id, 'name' => 'Front office']);
        ServiceLocation::factory()->for($customer)->create(['organization_id' => $organization->id, 'active' => false]);

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}/locations");

        $response->assertOk()->assertJsonCount(2, 'data');

        $activeOnly = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}/locations?active=1");
        $activeOnly->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $location->id)
            ->assertJsonPath('data.0.address.city', $location->city);
    }

    public function test_missing_token_ability_is_rejected_with_403(): void
    {
        [, $organization, $token] = $this->jarvisIdentity(['tickets.read']);
        Customer::factory()->for($organization)->create(['display_name' => 'T&S Communications']);

        $response = $this->bearer($token)->getJson('/api/v1/customers/search?q=T%26S');

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_revoked_membership_capability_is_rejected_with_403_even_with_a_valid_token(): void
    {
        [, , $token, $membership] = $this->jarvisIdentity();
        $capability = Capability::query()->where('key', 'api.customers.read')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);

        $response = $this->bearer($token)->getJson('/api/v1/customers/search?q=anything');

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    /**
     * @param  array<int, string>|null  $abilities
     * @return array{User, Organization, string, OrganizationMembership}
     */
    private function jarvisIdentity(?array $abilities = null): array
    {
        $organization = Organization::factory()->create(['active' => true]);
        $user = User::query()->create([
            'name' => 'JARVIS Core',
            'email' => 'jarvis-core+'.uniqid().'@service.newdaytech.net',
            'password' => Hash::make(str()->random(64)),
            'status' => 'service_account',
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());

        $abilities ??= ['customers.read', 'contacts.read', 'locations.read', 'projects.read', 'tickets.read', 'tickets.create', 'tickets.update', 'communications.create'];
        $token = $user->createToken('jarvis-core', $abilities)->plainTextToken;

        return [$user, $organization, $token, $membership];
    }

    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
