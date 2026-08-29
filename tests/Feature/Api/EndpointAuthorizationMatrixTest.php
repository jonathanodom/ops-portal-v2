<?php

namespace Tests\Feature\Api;

use App\Models\Capability;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C-011 — a single systematic pass proving the authentication and
 * authorization layer for EVERY /api/v1 endpoint, per plan §14
 * ("Authentication"/"Authorization" rows) rather than relying on each
 * endpoint's own feature test to happen to cover it. Every scoped route
 * shares the same auth:sanctum + abilities:<x> + capability:api.<x>
 * middleware stack (see routes/api.php); this test proves that stack
 * behaves identically for all of them, not just the ones with bespoke
 * 401/403 tests elsewhere.
 *
 * This intentionally does NOT re-test business logic, response shapes,
 * or validation content — that is already covered per-endpoint in
 * CustomerEndpointsTest, ContactSearchTest, TicketEndpointsTest, and
 * ProjectEndpointsTest. A non-401/403 status here (200, 400, 404, 422 —
 * whatever the endpoint's own logic produces for a dummy/empty request)
 * is treated as proof that authorization passed control to the
 * controller; the exact value is irrelevant to this test.
 */
class EndpointAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    /** @return array<string, array{string, string, ?string, ?string}> */
    public static function endpoints(): array
    {
        // [method, uri, requiredAbility, requiredCapability]
        // requiredAbility/requiredCapability are null for endpoints that only require a valid token (no scope).
        return [
            'GET /me' => ['GET', '/api/v1/me', null, null],
            'GET /customers/search' => ['GET', '/api/v1/customers/search', 'customers.read', 'api.customers.read'],
            'GET /customers/{id}' => ['GET', '/api/v1/customers/999999', 'customers.read', 'api.customers.read'],
            'GET /customers/{id}/locations' => ['GET', '/api/v1/customers/999999/locations', 'locations.read', 'api.locations.read'],
            'GET /contacts/search' => ['GET', '/api/v1/contacts/search', 'contacts.read', 'api.contacts.read'],
            'GET /tickets' => ['GET', '/api/v1/tickets', 'tickets.read', 'api.tickets.read'],
            'GET /tickets/{id}' => ['GET', '/api/v1/tickets/999999', 'tickets.read', 'api.tickets.read'],
            'POST /tickets' => ['POST', '/api/v1/tickets', 'tickets.create', 'api.tickets.create'],
            'PATCH /tickets/{id}' => ['PATCH', '/api/v1/tickets/999999', 'tickets.update', 'api.tickets.update'],
            'GET /customers/{id}/projects' => ['GET', '/api/v1/customers/999999/projects', 'projects.read', 'api.projects.read'],
            'GET /projects/{id}' => ['GET', '/api/v1/projects/999999', 'projects.read', 'api.projects.read'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_missing_token_is_rejected(string $method, string $uri, ?string $ability, ?string $capability): void
    {
        $this->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    #[DataProvider('endpoints')]
    public function test_invalid_token_is_rejected(string $method, string $uri, ?string $ability, ?string $capability): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    #[DataProvider('endpoints')]
    public function test_a_token_without_the_required_ability_is_rejected(string $method, string $uri, ?string $ability, ?string $capability): void
    {
        if ($ability === null) {
            $this->markTestSkipped('This endpoint requires no token ability beyond a valid identity.');
        }

        // communications.create is a real jarvis_service scope that no
        // endpoint under test requires, so a token holding only this
        // ability is guaranteed to be missing whatever `$ability` needs.
        [, , $token] = $this->jarvisIdentity(['communications.create']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    #[DataProvider('endpoints')]
    public function test_a_revoked_membership_capability_is_rejected_even_with_a_valid_token_ability(string $method, string $uri, ?string $ability, ?string $capability): void
    {
        if ($capability === null) {
            $this->markTestSkipped('This endpoint requires no membership capability beyond a valid identity.');
        }

        [, , $token, $membership] = $this->jarvisIdentity();
        $revoked = Capability::query()->where('key', $capability)->firstOrFail();
        $membership->capabilityOverrides()->attach($revoked, ['effect' => 'deny']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->json($method, $uri)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    #[DataProvider('endpoints')]
    public function test_a_fully_granted_identity_passes_authorization(string $method, string $uri, ?string $ability, ?string $capability): void
    {
        [, , $token] = $this->jarvisIdentity();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->json($method, $uri);
        $status = $response->getStatusCode();

        $this->assertNotContains(
            $status,
            [401, 403],
            "Expected any status other than 401/403 (proving the request reached the controller); got {$status}.",
        );
    }

    /** @return array{User, Organization, string, OrganizationMembership} */
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
}
