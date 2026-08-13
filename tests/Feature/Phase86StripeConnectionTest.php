<?php

namespace Tests\Feature;

use App\Contracts\StripeConnectionClient;
use App\Domain\StripeConnectionWorkflow;
use App\Models\Capability;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\User;
use App\Payments\StripePaymentProviderAdapter;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeConnectionClient;
use Tests\Support\RecordingStripeHttpClient;
use Tests\TestCase;

class Phase86StripeConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeConnectionClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->stripe = new FakeStripeConnectionClient;
        $this->app->instance(StripeConnectionClient::class, $this->stripe);
        config([
            'payments.connections.stripe.test.client_id' => 'ca_test_newday',
            'payments.connections.stripe.test.platform_secret' => 'sk_test_platform_secret',
        ]);
    }

    public function test_connect_redirect_uses_stripe_hosted_existing_account_authorization_and_hashed_state(): void
    {
        [$organization, $admin] = $this->actor();
        $this->actingAs($admin)->post('/office/settings/billing/payments/stripe/connect', ['environment' => 'test'])
            ->assertRedirectContains('connect.stripe.com/oauth/authorize');
        $call = $this->stripe->calls[0];
        $this->assertSame('ca_test_newday', $call['clientId']);
        $state = PaymentProviderAuthorizationState::query()->firstOrFail();
        $this->assertSame($organization->id, $state->organization_id);
        $this->assertNotSame($call['state'], $state->state_hash);
        $this->assertStringNotContainsString('sk_test_platform_secret', json_encode(DB::table('audit_events')->pluck('metadata'), JSON_THROW_ON_ERROR));
    }

    public function test_callback_stores_connected_account_identity_and_encrypted_deprecated_tokens(): void
    {
        [$organization, $admin] = $this->actor();
        $state = $this->start($organization, $admin);
        PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'stripe', 'environment' => 'test',
            'connection_method' => 'legacy_credentials', 'api_secret' => 'old-secret', 'webhook_secret' => 'old-webhook',
            'enabled' => false, 'connection_status' => 'connected',
        ]);

        $this->actingAs($admin)->get('/office/settings/billing/payments/stripe/callback?'.http_build_query(['state' => $state, 'code' => 'stripe-provider-code']))->assertRedirect('/office/settings/billing');
        $configuration = PaymentProviderConfiguration::query()->where('organization_id', $organization->id)->where('provider', 'stripe')->firstOrFail();
        $this->assertSame('oauth', $configuration->connection_method);
        $this->assertSame('acct_newday', $configuration->external_account_id);
        $this->assertSame('NewDay Tech LLC', $configuration->external_account_name);
        $this->assertTrue($configuration->payments_enabled);
        $this->assertFalse($configuration->enabled);
        $this->assertNull($configuration->api_secret);
        $raw = DB::table('payment_provider_configurations')->where('id', $configuration->id)->first();
        $this->assertStringNotContainsString('stripe-oauth-access', (string) $raw->oauth_access_token);
        $this->assertStringNotContainsString('stripe-oauth-refresh', (string) $raw->oauth_refresh_token);
        $this->assertStringNotContainsString('stripe-provider-code', json_encode(DB::table('audit_events')->pluck('metadata'), JSON_THROW_ON_ERROR));
    }

    public function test_denied_failed_replayed_and_cross_organization_callbacks_do_not_connect(): void
    {
        [$organization, $admin] = $this->actor();
        $state = $this->start($organization, $admin);
        $this->actingAs($admin)->get('/office/settings/billing/payments/stripe/callback?error=access_denied&state='.$state)->assertSessionHasErrors('connection');
        $this->actingAs($admin)->get('/office/settings/billing/payments/stripe/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertSessionHasErrors('connection');

        $state = $this->start($organization, $admin);
        $this->actingAs($admin)->get('/office/settings/billing/payments/stripe/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirect();
        $this->actingAs($admin)->get('/office/settings/billing/payments/stripe/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertSessionHasErrors('connection');

        [$otherOrganization] = $this->actor($admin);
        $this->assertDatabaseMissing('payment_provider_configurations', ['organization_id' => $otherOrganization->id, 'external_account_id' => 'acct_newday']);

        [$failedOrganization, $failedAdmin] = $this->actor();
        $failedState = $this->start($failedOrganization, $failedAdmin);
        $this->stripe->failExchange = true;
        $this->actingAs($failedAdmin)->get('/office/settings/billing/payments/stripe/callback?'.http_build_query(['state' => $failedState, 'code' => 'secret-code']))->assertSessionHasErrors('connection');
        $audit = json_encode(DB::table('audit_events')->where('organization_id', $failedOrganization->id)->pluck('metadata'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-code', $audit);
        $this->assertStringNotContainsString('stripe_exchange_secret_must_not_leak', $audit);
    }

    public function test_missing_application_configuration_reports_setup_prerequisite_without_secret_entry(): void
    {
        [, $admin] = $this->actor();
        config(['payments.connections.stripe.test.client_id' => null, 'payments.connections.stripe.test.platform_secret' => null]);
        $this->actingAs($admin)->post('/office/settings/billing/payments/stripe/connect', ['environment' => 'test'])->assertSessionHasErrors('connection');
        $response = $this->actingAs($admin)->get('/office/settings/billing')->assertOk();
        $response->assertSee('Stripe Connect setup required')->assertSee('callback URL')->assertDontSee('Secret key')->assertDontSee('Save Stripe credentials');
    }

    public function test_refresh_tracks_payment_readiness_and_disables_collection_when_account_loses_capability(): void
    {
        [$organization, $admin] = $this->actor();
        $configuration = $this->connected($organization, $admin, ['enabled' => true]);
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_payment_provider' => 'stripe']);
        $this->stripe->profileResult['payments_enabled'] = false;

        app(StripeConnectionWorkflow::class)->refresh($configuration, $admin);
        $configuration->refresh();
        $this->assertFalse($configuration->payments_enabled);
        $this->assertFalse($configuration->enabled);
        $this->assertNull(OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_payment_provider'));
    }

    public function test_disconnect_requires_disable_and_remote_deauthorization_before_local_clear(): void
    {
        [$organization, $admin] = $this->actor();
        $configuration = $this->connected($organization, $admin, ['enabled' => true]);
        $this->actingAs($admin)->delete('/office/settings/billing/payments/stripe/disconnect', ['confirmation' => 'DISCONNECT STRIPE'])->assertSessionHasErrors('connection');
        $configuration->update(['enabled' => false]);
        $this->stripe->failDeauthorize = true;
        $this->actingAs($admin)->delete('/office/settings/billing/payments/stripe/disconnect', ['confirmation' => 'DISCONNECT STRIPE'])->assertSessionHasErrors('connection');
        $this->assertSame('acct_newday', $configuration->fresh()->external_account_id);

        $this->stripe->failDeauthorize = false;
        $this->actingAs($admin)->delete('/office/settings/billing/payments/stripe/disconnect', ['confirmation' => 'DISCONNECT STRIPE'])->assertRedirect();
        $configuration->refresh();
        $this->assertSame('disconnected', $configuration->connection_status);
        $this->assertNull($configuration->external_account_id);
        $this->assertNull($configuration->oauth_access_token);
    }

    public function test_capability_denial_and_normal_secret_write_endpoint_are_enforced(): void
    {
        [, $admin, $membership] = $this->actor();
        $capability = Capability::query()->where('key', 'payments.settings.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability->id, ['effect' => 'deny']);
        $this->actingAs($admin)->post('/office/settings/billing/payments/stripe/connect', ['environment' => 'test'])->assertForbidden();
        $membership->capabilityOverrides()->detach($capability->id);
        $this->actingAs($admin)->put('/office/settings/billing/payments/stripe', ['environment' => 'test', 'api_secret' => 'forged-stripe-secret'])->assertNotFound();
    }

    public function test_connected_ui_shows_identity_payment_readiness_and_no_credential_inputs(): void
    {
        [$organization, $admin] = $this->actor();
        $this->connected($organization, $admin);
        $response = $this->actingAs($admin)->get('/office/settings/billing')->assertOk();
        $response->assertSee('NewDay Tech LLC')->assertSee('acct_newday')->assertSee('Payments enabled')->assertSee('Reconnect')->assertSee('Disconnect Stripe');
        $response->assertDontSee('Secret key')->assertDontSee('Webhook signing secret')->assertDontSee('stripe-oauth-access');
    }

    public function test_checkout_uses_platform_secret_and_connected_account_header(): void
    {
        [$organization, $admin] = $this->actor();
        $configuration = $this->connected($organization, $admin, ['enabled' => true]);
        $invoice = new Invoice(['invoice_number' => 'NDT-INV-2026-8603', 'billing_email' => 'billing@example.test']);
        $attempt = new PaymentAttempt(['amount_cents' => 1000, 'idempotency_key' => (string) Str::uuid()]);
        $attempt->id = 8603;
        $attempt->setRelation('invoice', $invoice);
        $http = new RecordingStripeHttpClient;
        $http->responses[] = [json_encode(['id' => 'cs_test_connected', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/session', 'expires_at' => now()->addHour()->timestamp], JSON_THROW_ON_ERROR), 200, []];
        ApiRequestor::setHttpClient($http);

        try {
            app(StripePaymentProviderAdapter::class)->createCheckout($configuration, $attempt, 'https://portal.example.test/return');
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        $headers = implode("\n", $http->requests[0]['headers']);
        $this->assertStringContainsString('Authorization: Bearer sk_test_platform_secret', $headers);
        $this->assertStringContainsString('Stripe-Account: acct_newday', $headers);
        $this->assertStringNotContainsString('stripe-oauth-access', $headers);
    }

    private function start(Organization $organization, User $admin): string
    {
        $result = app(StripeConnectionWorkflow::class)->start($organization, $admin, 'test', route('office.settings.billing.stripe.callback'));
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /** @param array<string, mixed> $overrides */
    private function connected(Organization $organization, User $admin, array $overrides = []): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->create(array_merge([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'stripe', 'environment' => 'test',
            'connection_method' => 'oauth', 'oauth_access_token' => 'stripe-oauth-access', 'oauth_refresh_token' => 'stripe-oauth-refresh',
            'enabled' => false, 'connection_status' => 'connected', 'external_account_id' => 'acct_newday',
            'external_account_name' => 'NewDay Tech LLC', 'payments_enabled' => true, 'connected_at' => now(), 'connected_by_id' => $admin->id,
        ], $overrides));
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function actor(?User $user = null): array
    {
        $organization = Organization::factory()->create();
        $user ??= User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());

        return [$organization, $user, $membership];
    }
}
