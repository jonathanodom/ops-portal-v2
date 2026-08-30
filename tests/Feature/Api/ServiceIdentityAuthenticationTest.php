<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ServiceIdentityAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_missing_token_is_rejected_with_the_standard_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonStructure(['error' => ['code', 'message', 'details'], 'meta' => ['request_id']]);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_revoked_token_is_rejected(): void
    {
        [$user, , $plainTextToken] = $this->jarvisServiceIdentity();
        $user->tokens()->delete();

        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_expired_token_is_rejected(): void
    {
        [$user] = $this->jarvisServiceIdentity();
        $expired = $user->createToken('expired', ['tickets.read'], now()->subMinute())->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$expired)->getJson('/api/v1/me');

        $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_valid_service_token_resolves_identity_and_reports_its_scopes(): void
    {
        [, $organization, $plainTextToken] = $this->jarvisServiceIdentity();

        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.scopes', [
                'customers.read', 'contacts.read', 'locations.read', 'projects.read',
                'tickets.read', 'tickets.create', 'tickets.update', 'communications.create',
            ])
            ->assertJsonStructure(['data' => ['actor' => ['id', 'name'], 'organization_id', 'scopes'], 'meta' => ['request_id']]);
    }

    public function test_service_token_with_narrowed_abilities_only_reports_the_granted_scopes(): void
    {
        [, , $plainTextToken, $membership] = $this->jarvisServiceIdentity(['tickets.read']);

        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('data.scopes', ['tickets.read']);
        $this->assertTrue($membership->hasCapability('api.tickets.read'));
    }

    public function test_inactive_membership_is_denied_even_with_a_valid_token(): void
    {
        [$user, , $plainTextToken, $membership] = $this->jarvisServiceIdentity();
        $membership->update(['status' => 'inactive']);

        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)->getJson('/api/v1/me');

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_x_request_id_is_echoed_on_both_success_and_error_responses(): void
    {
        $requestId = '11111111-1111-1111-1111-111111111111';

        $missingToken = $this->withHeader('X-Request-ID', $requestId)->getJson('/api/v1/me');
        $missingToken->assertJsonPath('meta.request_id', $requestId);
        $this->assertSame($requestId, $missingToken->headers->get('X-Request-ID'));

        [, , $plainTextToken] = $this->jarvisServiceIdentity();
        $authenticated = $this->withHeader('X-Request-ID', $requestId)
            ->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/api/v1/me');
        $authenticated->assertJsonPath('meta.request_id', $requestId);
    }

    public function test_read_and_write_rate_limits_are_separate_and_use_the_api_envelope(): void
    {
        config()->set('jarvis.api_read_limit_per_minute', 1);
        config()->set('jarvis.api_write_limit_per_minute', 1);
        [, , $plainTextToken] = $this->jarvisServiceIdentity();
        $headers = [
            'Authorization' => 'Bearer '.$plainTextToken,
            'X-Request-ID' => '22222222-2222-2222-2222-222222222222',
            'Idempotency-Key' => 'rate-limit-write-1',
        ];

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/tickets', [])->assertStatus(422);

        $readLimited = $this->withHeaders($headers)->getJson('/api/v1/me');
        $readLimited->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame($headers['X-Request-ID'], $readLimited->headers->get('X-Request-ID'));

        $writeLimited = $this->withHeaders($headers)->postJson('/api/v1/tickets', []);
        $writeLimited->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_rate_limits_are_isolated_by_service_identity(): void
    {
        config()->set('jarvis.api_read_limit_per_minute', 1);
        [, $organization, $firstToken] = $this->jarvisServiceIdentity();
        $secondUser = User::query()->create([
            'name' => 'JARVIS Secondary',
            'email' => 'jarvis-secondary@service.newdaytech.net',
            'password' => Hash::make(str()->random(64)),
            'status' => 'service_account',
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $secondUser->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());
        $secondToken = $secondUser->createToken('secondary', ['tickets.read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$firstToken)->getJson('/api/v1/me')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$firstToken)->getJson('/api/v1/me')->assertStatus(429);
        $this->flushHeaders();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$secondToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_api_request_size_limit_returns_an_enveloped_413_without_affecting_browser_routes(): void
    {
        config()->set('jarvis.api_max_request_bytes', 128);
        [, , $plainTextToken] = $this->jarvisServiceIdentity();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
            'X-Request-ID' => '33333333-3333-3333-3333-333333333333',
        ])
            ->postJson('/api/v1/tickets', ['description' => str_repeat('x', 256)]);

        $response->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large')
            ->assertJsonPath('meta.request_id', '33333333-3333-3333-3333-333333333333');
        $this->flushHeaders();
        $this->get('/up')->assertOk();
    }

    /**
     * @param  array<int, string>|null  $abilities  Defaults to the full jarvis_service scope set.
     * @return array{User, Organization, string, OrganizationMembership}
     */
    private function jarvisServiceIdentity(?array $abilities = null): array
    {
        $organization = Organization::query()->create([
            'name' => 'NewDay Tech LLC',
            'slug' => 'newday-tech',
            'timezone' => 'America/Chicago',
            'active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'JARVIS Core',
            'email' => 'jarvis-core@service.newdaytech.net',
            'password' => Hash::make(str()->random(64)),
            'status' => 'service_account',
        ]);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'jarvis_service')->firstOrFail());

        $abilities ??= [
            'customers.read', 'contacts.read', 'locations.read', 'projects.read',
            'tickets.read', 'tickets.create', 'tickets.update', 'communications.create',
        ];
        $plainTextToken = $user->createToken('jarvis-core', $abilities)->plainTextToken;

        return [$user, $organization, $plainTextToken, $membership];
    }
}
