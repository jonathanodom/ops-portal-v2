<?php

namespace Tests\Feature;

use App\Contracts\SquareConnectionClient;
use App\Domain\SquareConnectionWorkflow;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeSquareConnectionClient;
use Tests\TestCase;

class Phase86SquareOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeSquareConnectionClient $square;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->square = new FakeSquareConnectionClient;
        $this->app->instance(SquareConnectionClient::class, $this->square);
        config([
            'payments.connections.square.sandbox.application_id' => 'square-app-id',
            'payments.connections.square.sandbox.application_secret' => 'square-app-secret',
        ]);
    }

    public function test_connect_redirect_uses_square_hosted_authorization_least_privilege_scopes_and_hashed_state(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $response = $this->actingAs($admin)->post('/office/settings/billing/payments/square/connect', ['environment' => 'sandbox']);
        $response->assertRedirectContains('connect.squareupsandbox.com/oauth2/authorize');
        $call = $this->square->calls[0];
        $this->assertSame(['MERCHANT_PROFILE_READ', 'ORDERS_READ', 'ORDERS_WRITE', 'PAYMENTS_READ', 'PAYMENTS_WRITE'], $call['scopes']);
        $this->assertSame(43, strlen($call['codeChallenge']));
        $state = PaymentProviderAuthorizationState::query()->firstOrFail();
        $this->assertSame($organization->id, $state->organization_id);
        $this->assertNotSame($call['state'], $state->state_hash);
        $this->assertStringNotContainsString('square-app-secret', json_encode(DB::table('audit_events')->pluck('metadata'), JSON_THROW_ON_ERROR));
    }

    public function test_callback_exchanges_code_and_snapshots_encrypted_merchant_and_location_connection(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $state = $this->start($organization, $admin);
        PaymentProviderConfiguration::query()->create([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'square', 'environment' => 'sandbox',
            'connection_method' => 'legacy_credentials', 'api_secret' => 'old-legacy-token', 'webhook_secret' => 'old-webhook',
            'location_id' => 'OLD-LOCATION', 'enabled' => false, 'connection_status' => 'connected',
        ]);

        $this->actingAs($admin)->get('/office/settings/billing/payments/square/callback?'.http_build_query(['state' => $state, 'code' => 'provider-code']))->assertRedirect('/office/settings/billing');
        $configuration = PaymentProviderConfiguration::query()->where('organization_id', $organization->id)->where('provider', 'square')->firstOrFail();
        $this->assertSame('oauth', $configuration->connection_method);
        $this->assertSame('MERCHANT-1', $configuration->external_account_id);
        $this->assertSame('NewDay Square Merchant', $configuration->external_account_name);
        $this->assertSame('LOC-2', $configuration->location_id);
        $this->assertSame('Graham', $configuration->location_name);
        $this->assertFalse($configuration->enabled);
        $this->assertNull($configuration->api_secret);
        $this->assertNull($configuration->webhook_secret);
        $raw = DB::table('payment_provider_configurations')->where('id', $configuration->id)->first();
        $this->assertStringNotContainsString('square-access-token', $raw->oauth_access_token);
        $this->assertStringNotContainsString('square-refresh-token', $raw->oauth_refresh_token);
        $this->assertStringNotContainsString('provider-code', json_encode(DB::table('audit_events')->pluck('metadata'), JSON_THROW_ON_ERROR));
    }

    public function test_invalid_replayed_cross_organization_and_failed_callbacks_do_not_connect_an_account(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        [$otherOrganization] = $this->actor('super_admin', $admin);
        $state = $this->start($organization, $admin);

        $this->actingAs($admin)->get('/office/settings/billing/payments/square/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertRedirect();
        $this->actingAs($admin)->get('/office/settings/billing/payments/square/callback?'.http_build_query(['state' => $state, 'code' => 'code']))->assertSessionHasErrors('connection');
        $this->assertDatabaseMissing('payment_provider_configurations', ['organization_id' => $otherOrganization->id, 'external_account_id' => 'MERCHANT-1']);

        [$failedOrganization, $failedAdmin] = $this->actor('super_admin');
        $failedState = $this->start($failedOrganization, $failedAdmin);
        $this->square->failExchange = true;
        $this->actingAs($failedAdmin)->get('/office/settings/billing/payments/square/callback?'.http_build_query(['state' => $failedState, 'code' => 'secret-provider-code']))->assertSessionHasErrors('connection');
        $this->assertDatabaseMissing('payment_provider_configurations', ['organization_id' => $failedOrganization->id, 'connection_method' => 'oauth']);
        $audit = json_encode(DB::table('audit_events')->where('organization_id', $failedOrganization->id)->pluck('metadata'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret-provider-code', $audit);
        $this->assertStringNotContainsString('provider_exchange_secret', $audit);
    }

    public function test_refresh_rotates_tokens_preserves_selected_location_and_failed_refresh_disables_collection(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $configuration = $this->connected($organization, $admin, ['location_id' => 'LOC-1', 'location_name' => 'Dallas', 'enabled' => true]);
        $workflow = app(SquareConnectionWorkflow::class);

        $workflow->refresh($configuration, $admin);
        $configuration->refresh();
        $this->assertSame('square-access-refreshed', $configuration->oauth_access_token);
        $this->assertSame('square-refresh-refreshed', $configuration->oauth_refresh_token);
        $this->assertSame('LOC-1', $configuration->location_id);
        $this->assertTrue($configuration->enabled);

        $this->square->failRefresh = true;
        try {
            $workflow->refresh($configuration, $admin);
            $this->fail('Failed refresh must not be treated as connected.');
        } catch (ValidationException) {
            $this->assertFalse($configuration->fresh()->enabled);
            $this->assertSame('refresh_failed', $configuration->fresh()->connection_status);
        }
    }

    public function test_location_selection_is_limited_to_active_discovered_locations(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $configuration = $this->connected($organization, $admin);

        $this->actingAs($admin)->put('/office/settings/billing/payments/square/location', ['location_id' => 'LOC-1'])->assertRedirect();
        $this->assertSame('Dallas', $configuration->fresh()->location_name);
        $this->actingAs($admin)->put('/office/settings/billing/payments/square/location', ['location_id' => 'FORGED'])->assertSessionHasErrors('location_id');
        $this->assertSame('LOC-1', $configuration->fresh()->location_id);
    }

    public function test_disconnect_requires_disable_confirmation_and_provider_revocation_before_local_clear(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $configuration = $this->connected($organization, $admin, ['enabled' => true]);
        OrganizationBillingSetting::query()->create(['organization_id' => $organization->id, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_payment_provider' => 'square']);

        $this->actingAs($admin)->delete('/office/settings/billing/payments/square/disconnect', ['confirmation' => 'DISCONNECT SQUARE'])->assertSessionHasErrors('connection');
        $configuration->update(['enabled' => false]);
        $this->square->failRevoke = true;
        $this->actingAs($admin)->delete('/office/settings/billing/payments/square/disconnect', ['confirmation' => 'DISCONNECT SQUARE'])->assertSessionHasErrors('connection');
        $this->assertSame('square-access-token', $configuration->fresh()->oauth_access_token);

        $this->square->failRevoke = false;
        $this->actingAs($admin)->delete('/office/settings/billing/payments/square/disconnect', ['confirmation' => 'DISCONNECT SQUARE'])->assertRedirect();
        $configuration->refresh();
        $this->assertSame('disconnected', $configuration->connection_status);
        $this->assertNull($configuration->oauth_access_token);
        $this->assertNull($configuration->external_account_id);
        $this->assertNull(OrganizationBillingSetting::query()->where('organization_id', $organization->id)->value('default_payment_provider'));
    }

    public function test_reconnect_and_disconnect_are_blocked_by_active_attempts_and_capability_denials(): void
    {
        [$organization, $admin, $membership] = $this->actor('super_admin');
        $configuration = $this->connected($organization, $admin);
        $invoice = $this->invoice($organization, $admin);
        PaymentAttempt::query()->create([
            'organization_id' => $organization->id, 'invoice_id' => $invoice->id, 'payment_provider_configuration_id' => $configuration->id,
            'provider' => 'square', 'amount_cents' => 1000, 'status' => 'processing', 'idempotency_key' => fake()->uuid(),
            'return_token_hash' => hash('sha256', fake()->uuid()), 'initiated_by_id' => $admin->id,
        ]);
        $this->actingAs($admin)->post('/office/settings/billing/payments/square/connect', ['environment' => 'sandbox'])->assertSessionHasErrors('connection');
        $this->actingAs($admin)->delete('/office/settings/billing/payments/square/disconnect', ['confirmation' => 'DISCONNECT SQUARE'])->assertSessionHasErrors('connection');

        $capability = Capability::query()->where('key', 'payments.settings.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability->id, ['effect' => 'deny']);
        $this->actingAs($admin)->post('/office/settings/billing/payments/square/connect', ['environment' => 'sandbox'])->assertForbidden();
    }

    public function test_billing_settings_removes_normal_square_secret_inputs_and_shows_connected_state(): void
    {
        [$organization, $admin] = $this->actor('super_admin');
        $this->connected($organization, $admin);
        $response = $this->actingAs($admin)->get('/office/settings/billing')->assertOk();
        $response->assertSee('Provider hosted')->assertSee('NewDay Square Merchant')->assertSee('Graham')->assertSee('Reconnect')->assertSee('Disconnect Square');
        $response->assertDontSee('Access token')->assertDontSee('Save Square credentials')->assertDontSee('square-access-token');
        $this->actingAs($admin)->put('/office/settings/billing/payments/square', ['environment' => 'sandbox', 'api_secret' => 'forged-square-token', 'location_id' => 'FORGED'])->assertNotFound();
    }

    private function start(Organization $organization, User $admin): string
    {
        $result = app(SquareConnectionWorkflow::class)->start($organization, $admin, 'sandbox', route('office.settings.billing.square.callback'));
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /** @param array<string, mixed> $overrides */
    private function connected(Organization $organization, User $admin, array $overrides = []): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->create(array_merge([
            'organization_id' => $organization->id, 'public_id' => fake()->uuid(), 'provider' => 'square', 'environment' => 'sandbox',
            'connection_method' => 'oauth', 'oauth_access_token' => 'square-access-token', 'oauth_refresh_token' => 'square-refresh-token',
            'oauth_expires_at' => now()->addMonth(), 'location_id' => 'LOC-2', 'location_name' => 'Graham',
            'available_locations' => [['id' => 'LOC-1', 'name' => 'Dallas'], ['id' => 'LOC-2', 'name' => 'Graham']],
            'enabled' => false, 'connection_status' => 'connected', 'external_account_id' => 'MERCHANT-1', 'external_account_name' => 'NewDay Square Merchant',
            'connected_at' => now(), 'connected_by_id' => $admin->id,
        ], $overrides));
    }

    private function invoice(Organization $organization, User $actor): Invoice
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-8601', 'title' => 'Square connection fixture', 'priority' => 'normal', 'source' => 'phone', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'timezone' => $location->timezone, 'ticket_visit_number' => 1, 'status' => 'approved']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved', 'diagnosis' => 'Fixture', 'work_performed' => 'Fixture', 'submitted_token' => fake()->uuid(), 'submitted_by_id' => $actor->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'handed_off', 'created_by_id' => $actor->id]);
        $invoice = Invoice::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'service_ticket_id' => $ticket->id, 'billing_handoff_id' => $handoff->id, 'generation' => 1, 'invoice_number' => 'NDT-INV-2026-8601', 'status' => 'issued', 'currency' => 'USD', 'payment_terms' => 'due_on_receipt', 'billing_name' => $customer->display_name, 'seller_name' => 'NewDay Tech', 'subtotal_cents' => 1000, 'total_cents' => 1000, 'creation_token' => fake()->uuid(), 'issue_token' => fake()->uuid(), 'issued_at' => now(), 'issued_by_id' => $actor->id, 'pdf_status' => 'pending', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
        $handoff->update(['current_invoice_id' => $invoice->id]);

        return $invoice;
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
