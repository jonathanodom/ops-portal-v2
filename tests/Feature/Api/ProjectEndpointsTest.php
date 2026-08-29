<?php

namespace Tests\Feature\Api;

use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_customer_projects_lists_only_that_customers_projects_with_status_filter(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $other = Customer::factory()->for($organization)->create();
        $active = $this->createProject($organization, $customer, ['status' => 'active', 'project_number' => 'NDT-PJ-2026-0001']);
        $this->createProject($organization, $customer, ['status' => 'completed', 'project_number' => 'NDT-PJ-2026-0002']);
        $this->createProject($organization, $other, ['status' => 'active', 'project_number' => 'NDT-PJ-2026-0003']);

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}/projects?status=active");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $active->id)
            ->assertJsonPath('data.0.customer_id', (string) $customer->id)
            ->assertJsonStructure(['data' => [['id', 'project_number', 'customer_id', 'location_id', 'name', 'type', 'status', 'summary', 'objective', 'start_on', 'target_end_on', 'completed_at', 'created_at', 'updated_at']]]);
    }

    public function test_customer_projects_returns_not_found_for_a_customer_in_another_organization(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}/projects");

        $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    }

    public function test_customer_projects_rejects_an_invalid_status_filter(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();

        $response = $this->bearer($token)->getJson("/api/v1/customers/{$customer->id}/projects?status=not-a-real-status");

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_show_returns_the_project_summary(): void
    {
        [, $organization, $token] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $project = $this->createProject($organization, $customer);

        $response = $this->bearer($token)->getJson("/api/v1/projects/{$project->id}");

        $response->assertOk()->assertJsonPath('data.id', (string) $project->id);
    }

    public function test_show_returns_not_found_for_a_project_in_another_organization_without_leaking_internals(): void
    {
        [, , $token] = $this->jarvisIdentity();
        $other = Organization::factory()->create(['active' => true]);
        $customer = Customer::factory()->for($other)->create();
        $project = $this->createProject($other, $customer);

        $response = $this->bearer($token)->getJson("/api/v1/projects/{$project->id}");

        $response->assertStatus(404)
            ->assertJsonPath('error.message', 'The requested resource was not found.')
            ->assertDontSeeText('App\\Models\\Project');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'security.cross_organization_record_denied']);
    }

    public function test_missing_token_ability_is_rejected_with_403(): void
    {
        [, $organization, , $membership] = $this->jarvisIdentity(['tickets.read']);
        $customer = Customer::factory()->for($organization)->create();
        $project = $this->createProject($organization, $customer);
        $token = $membership->user->createToken('read-only', ['tickets.read'])->plainTextToken;

        $response = $this->bearer($token)->getJson("/api/v1/projects/{$project->id}");

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_revoked_membership_capability_is_rejected_with_403(): void
    {
        [, $organization, $token, $membership] = $this->jarvisIdentity();
        $customer = Customer::factory()->for($organization)->create();
        $project = $this->createProject($organization, $customer);
        $capability = Capability::query()->where('key', 'api.projects.read')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);

        $response = $this->bearer($token)->getJson("/api/v1/projects/{$project->id}");

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    /** @param array<string, mixed> $overrides */
    private function createProject(Organization $organization, Customer $customer, array $overrides = []): Project
    {
        return Project::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_number' => 'NDT-PJ-2026-'.str_pad((string) (Project::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'name' => 'Test project',
            'type' => 'installation_project',
            'status' => 'planning',
        ], $overrides));
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
