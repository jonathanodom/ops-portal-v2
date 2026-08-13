<?php

namespace Tests\Feature;

use App\Domain\PaymentAuthorizationStateWorkflow;
use App\Domain\PaymentSettingsWorkflow;
use App\Models\Capability;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase86ConnectedPaymentsFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_connected_metadata_is_additive_encrypted_and_legacy_credentials_remain_ready(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $legacy = PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id,
            'public_id' => fake()->uuid(),
            'provider' => 'stripe',
            'environment' => 'test',
            'api_secret' => 'sk_test_legacy',
            'enabled' => true,
            'connection_status' => 'connected',
        ]);
        $legacy->refresh();
        $this->assertSame('legacy_credentials', $legacy->connection_method);
        $this->assertTrue($legacy->isReady());

        $oauth = PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id,
            'public_id' => fake()->uuid(),
            'provider' => 'square',
            'environment' => 'sandbox',
            'connection_method' => 'oauth',
            'oauth_access_token' => 'square-access-secret',
            'oauth_refresh_token' => 'square-refresh-secret',
            'location_id' => 'LOCATION',
            'enabled' => true,
            'connection_status' => 'connected',
            'connected_at' => now(),
            'connected_by_id' => $admin->id,
        ]);
        $raw = DB::table('payment_provider_configurations')->where('id', $oauth->id)->first();
        $this->assertStringNotContainsString('square-access-secret', $raw->oauth_access_token);
        $this->assertStringNotContainsString('square-refresh-secret', $raw->oauth_refresh_token);
        $this->assertArrayNotHasKey('oauth_access_token', $oauth->toArray());
        $this->assertTrue($oauth->isReady());
    }

    public function test_authorization_state_is_hashed_encrypted_short_lived_and_single_use(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $workflow = app(PaymentAuthorizationStateWorkflow::class);
        $result = $workflow->create($organization, $admin, 'square', 'sandbox', '/office/settings/billing');
        $record = $result['authorization_state'];
        $raw = DB::table('payment_provider_authorization_states')->where('id', $record->id)->first();

        $this->assertNotSame($result['state'], $raw->state_hash);
        $this->assertSame(hash('sha256', $result['state']), $raw->state_hash);
        $this->assertStringNotContainsString($result['code_verifier'], $raw->pkce_verifier);
        $this->assertSame(43, strlen($result['code_challenge']));
        $this->assertTrue($record->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));

        $consumed = $workflow->consume($organization, $admin, 'square', $result['state']);
        $this->assertNotNull($consumed->consumed_at);
        $this->expectException(ValidationException::class);
        $workflow->consume($organization, $admin, 'square', $result['state']);
    }

    public function test_authorization_state_cannot_cross_organization_actor_or_expiration_boundaries(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        [$otherOrganization] = $this->actor('super_admin', $admin);
        $workflow = app(PaymentAuthorizationStateWorkflow::class);
        $result = $workflow->create($organization, $admin, 'stripe', 'test');

        try {
            $workflow->consume($otherOrganization, $admin, 'stripe', $result['state']);
            $this->fail('Cross-organization authorization state reuse must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('connection', $exception->errors());
        }

        PaymentProviderAuthorizationState::query()->whereKey($result['authorization_state']->id)->update(['expires_at' => now()->subMinute()]);
        $this->expectException(ValidationException::class);
        $workflow->consume($organization, $admin, 'stripe', $result['state']);
    }

    public function test_only_a_ready_provider_can_be_the_default_and_disabling_it_clears_the_default(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $square = PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'square',
            'environment' => 'sandbox', 'api_secret' => 'square-secret', 'location_id' => 'LOCATION',
            'enabled' => true, 'connection_status' => 'connected',
        ]);
        PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'stripe',
            'environment' => 'test', 'api_secret' => 'stripe-secret', 'enabled' => false, 'connection_status' => 'connected',
        ]);

        $this->actingAs($admin)->put('/office/settings/billing/payments/default', ['default_payment_provider' => 'square'])->assertRedirect();
        $this->assertSame('square', OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_payment_provider'));
        $this->actingAs($admin)->put('/office/settings/billing/payments/default', ['default_payment_provider' => 'stripe'])->assertSessionHasErrors('default_payment_provider');

        app(PaymentSettingsWorkflow::class)->setEnabled($square, $admin, false, false);
        $this->assertNull(OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_payment_provider'));
    }

    public function test_default_provider_respects_seeded_roles_explicit_denials_and_organization_scope(): void
    {
        [$organization, $admin, $membership] = $this->actor('super_admin');
        [$billingOrganization, $billing] = $this->actor('billing');
        $this->actingAs($billing)->put('/office/settings/billing/payments/default', ['default_payment_provider' => null])->assertForbidden();

        $capability = Capability::query()->where('key', 'payments.settings.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability->id, ['effect' => 'deny']);
        $this->actingAs($admin)->put('/office/settings/billing/payments/default', ['default_payment_provider' => null])->assertForbidden();
        $this->assertDatabaseMissing('organization_billing_settings', ['organization_id' => $billingOrganization->id, 'default_payment_provider' => 'square']);
    }

    public function test_application_credentials_remain_server_side_and_never_render_in_billing_settings(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        config(['payments.connections.square.sandbox.application_secret' => 'server-application-secret']);
        $this->assertSame('server-application-secret', config('payments.connections.square.sandbox.application_secret'));
        $this->actingAs($admin)->get('/office/settings/billing')->assertOk()->assertDontSee('server-application-secret');
    }

    public function test_hosted_connection_blocks_legacy_secret_rotation_without_auditing_secrets(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $configuration = PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'stripe',
            'environment' => 'test', 'connection_method' => 'oauth', 'oauth_access_token' => 'old-oauth-access',
            'oauth_refresh_token' => 'old-oauth-refresh', 'connection_status' => 'connected', 'external_account_id' => 'acct_old',
        ]);

        try {
            app(PaymentSettingsWorkflow::class)->save($configuration, $admin, [
                'environment' => 'test', 'api_secret' => 'new-legacy-secret', 'webhook_secret' => 'new-webhook-secret',
            ]);
            $this->fail('Hosted connections must be disconnected before legacy credentials can be used.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('provider', $exception->errors());
        }

        $configuration->refresh();
        $this->assertSame('oauth', $configuration->connection_method);
        $this->assertSame('old-oauth-access', $configuration->oauth_access_token);
        $this->assertSame('old-oauth-refresh', $configuration->oauth_refresh_token);
        $this->assertDatabaseMissing('audit_events', ['organization_id' => $organization->id, 'event_type' => 'payments.provider_credentials_updated']);
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function actor(string $role, ?User $user = null): array
    {
        $organization = Organization::factory()->create();
        $user ??= User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$organization, $user, $membership];
    }
}
