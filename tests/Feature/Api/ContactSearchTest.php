<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_search_matches_by_name(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $contact = Contact::factory()->for($customer)->create(['organization_id' => $organization->id, 'name' => 'Ken Alvarez']);
        Contact::factory()->for($customer)->create(['organization_id' => $organization->id, 'name' => 'Someone Else']);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=Ken');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $contact->id)
            ->assertJsonPath('data.0.customer_id', (string) $customer->id)
            ->assertJsonPath('data.0.phones.0', $contact->phone)
            ->assertJsonStructure(['data' => [['id', 'customer_id', 'name', 'phones', 'emails']], 'meta' => ['request_id']]);
    }

    public function test_search_matches_by_phone_digits(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $contact = Contact::factory()->for($customer)->create([
            'organization_id' => $organization->id,
            'phone' => '(512) 555-0134',
            'phone_normalized' => '5125550134',
        ]);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=555-0134');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $contact->id);
    }

    public function test_search_matches_by_email(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $contact = Contact::factory()->for($customer)->create(['organization_id' => $organization->id, 'email' => 'distinctive.contact@example.test']);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=distinctive.contact');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $contact->id);
    }

    public function test_search_returns_an_empty_array_when_nothing_matches(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        Contact::factory()->for($customer)->create(['organization_id' => $organization->id, 'name' => 'Ken Alvarez']);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=NoSuchContactNameAtAll');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_respects_the_limit_parameter(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        Contact::factory()->for($customer)->count(5)->create(['organization_id' => $organization->id, 'name' => 'Repeatable Contact Name']);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=Repeatable&limit=2');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_rejects_a_limit_above_twenty(): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=anything&limit=21');

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_inactive_contacts_are_excluded(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        Contact::factory()->for($customer)->create(['organization_id' => $organization->id, 'name' => 'Retired Contact', 'active' => false]);

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=Retired');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_requires_a_query_parameter(): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search');

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_missing_token_ability_is_rejected_with_403(): void
    {
        $organization = Organization::factory()->create(['active' => true]);
        $user = User::query()->create([
            'name' => 'JARVIS Core',
            'email' => 'jarvis-core+'.uniqid().'@service.newdaytech.net',
            'password' => Hash::make(str()->random(64)),
            'status' => 'service_account',
        ]);
        OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active'])
            ->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());
        $token = $user->createToken('narrow', ['tickets.read'])->plainTextToken;

        $response = $this->bearer($token)->getJson('/api/v1/contacts/search?q=anything');

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    /** @return array{User, Organization, string, OrganizationMembership} */
    private function jarvisIdentity(): array
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
        $token = $user->createToken('jarvis-core', ['customers.read', 'contacts.read', 'locations.read'])->plainTextToken;

        return [$user, $organization, $token, $membership];
    }

    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
