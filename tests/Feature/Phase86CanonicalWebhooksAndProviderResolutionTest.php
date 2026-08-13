<?php

namespace Tests\Feature;

use App\Domain\PaymentWorkflow;
use App\Jobs\RenderPaymentReceiptPdf;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Payments\CanonicalPaymentWebhookRouter;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase86CanonicalWebhooksAndProviderResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['payments.fake' => true, 'payments.connections.stripe.test.platform_secret' => 'sk_test_platform']);
    }

    public function test_stripe_canonical_webhook_verifies_connect_signature_routes_account_and_is_idempotent(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $configuration = $this->provider($invoice, 'stripe', 'test', 'acct_newday');
        $attempt = $this->attempt($invoice, $configuration, 'stripe', ['provider_session_id' => 'cs_connected']);
        config(['payments.connections.stripe.test.webhook_secret' => 'whsec_connect_test']);
        $body = json_encode([
            'id' => 'evt_connected_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false,
            'account' => 'acct_newday', 'data' => ['object' => ['id' => 'cs_connected', 'object' => 'checkout.session',
                'metadata' => ['payment_attempt_id' => (string) $attempt->id], 'payment_intent' => 'pi_connected', 'amount_total' => 4000]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_connect_test');
        $routed = app(CanonicalPaymentWebhookRouter::class)->route('stripe', $body, ['stripe-signature' => $signature], 'http://localhost/webhooks/payments/stripe');
        $this->assertSame($configuration->id, $routed['configuration']->id);

        $this->call('POST', '/webhooks/payments/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame('stripe', $invoice->fresh()->electronic_payment_provider);
        Queue::assertPushed(RenderPaymentReceiptPdf::class);
    }

    public function test_square_canonical_webhook_verifies_application_signature_and_routes_merchant(): void
    {
        [$invoice] = $this->invoiceScenario();
        $configuration = $this->provider($invoice, 'square', 'sandbox', 'MERCHANT-8600');
        $attempt = $this->attempt($invoice, $configuration, 'square', ['provider_order_id' => 'order-square']);
        config(['payments.connections.square.sandbox.webhook_signature_key' => 'square-app-signature']);
        $body = json_encode([
            'event_id' => 'square-connected-1', 'merchant_id' => 'MERCHANT-8600', 'type' => 'payment.updated',
            'data' => ['object' => ['payment' => ['id' => 'payment-square', 'order_id' => 'order-square', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 4000]]]],
        ], JSON_THROW_ON_ERROR);
        $url = 'http://localhost/webhooks/payments/square';
        $signature = base64_encode(hash_hmac('sha256', $url.$body, 'square-app-signature', true));
        $routed = app(CanonicalPaymentWebhookRouter::class)->route('square', $body, ['x-square-hmacsha256-signature' => $signature], $url);
        $this->assertSame($configuration->id, $routed['configuration']->id);

        $this->call('POST', '/webhooks/payments/square', [], [], [], ['HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('succeeded', $attempt->fresh()->status);
        $this->assertSame('square', $invoice->fresh()->electronic_payment_provider);
        $this->assertDatabaseHas('payment_webhook_events', ['payment_provider_configuration_id' => $configuration->id, 'provider_event_id' => 'square-connected-1']);
    }

    public function test_canonical_webhooks_reject_invalid_signatures_unknown_identity_and_cross_configuration_attempts(): void
    {
        [$invoice] = $this->invoiceScenario();
        $knownConfiguration = $this->provider($invoice, 'stripe', 'test', 'acct_known');
        config(['payments.connections.stripe.test.webhook_secret' => 'whsec_connect_test']);
        $payload = fn (string $account, int $attemptId): string => json_encode([
            'id' => 'evt_'.Str::random(8), 'object' => 'event', 'type' => 'checkout.session.completed', 'livemode' => false,
            'account' => $account, 'data' => ['object' => ['id' => 'cs_unknown', 'object' => 'checkout.session',
                'metadata' => ['payment_attempt_id' => (string) $attemptId], 'payment_intent' => 'pi_unknown', 'amount_total' => 1000]],
        ], JSON_THROW_ON_ERROR);

        $unknown = $payload('acct_unknown', 9999);
        $timestamp = time();
        $unknownSignature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$unknown, 'whsec_connect_test');
        $this->withHeader('Stripe-Signature', $unknownSignature)->call('POST', '/webhooks/payments/stripe', [], [], [], [], $unknown)->assertBadRequest();
        $this->withHeader('Stripe-Signature', 't='.$timestamp.',v1=invalid')->call('POST', '/webhooks/payments/stripe', [], [], [], [], $unknown)->assertBadRequest();
        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseCount('payment_transactions', 0);

        [$foreignInvoice] = $this->invoiceScenario();
        $foreignConfiguration = $this->provider($foreignInvoice, 'stripe', 'test', 'acct_foreign');
        $foreignAttempt = $this->attempt($foreignInvoice, $foreignConfiguration, 'stripe', ['provider_session_id' => 'cs_foreign']);
        $crossConfiguration = $payload('acct_known', $foreignAttempt->id);
        $crossTimestamp = time();
        $crossSignature = 't='.$crossTimestamp.',v1='.hash_hmac('sha256', $crossTimestamp.'.'.$crossConfiguration, 'whsec_connect_test');
        $this->call('POST', '/webhooks/payments/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $crossSignature, 'CONTENT_TYPE' => 'application/json'], $crossConfiguration)->assertOk();
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_provider_configuration_id' => $knownConfiguration->id,
            'status' => 'unmatched',
            'safe_failure_code' => 'attempt_not_found',
        ]);
        $this->assertSame('processing', $foreignAttempt->fresh()->status);
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_checkout_resolution_uses_default_then_sole_ready_provider_without_invoice_selector(): void
    {
        [$invoice, $admin] = $this->invoiceScenario();
        $stripe = $this->provider($invoice, 'stripe', 'test', 'acct_default');
        $square = $this->provider($invoice, 'square', 'sandbox', 'MERCHANT-SOLE', ['location_id' => 'LOC', 'enabled' => false]);
        OrganizationBillingSetting::query()->create(['organization_id' => $invoice->organization_id, 'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt', 'default_payment_provider' => 'stripe']);
        $invoice->forceFill(['preferred_payment_provider' => null, 'electronic_payment_provider' => null, 'payment_provider_locked_at' => null])->save();

        $first = app(PaymentWorkflow::class)->createCheckout($invoice, $admin, 1000, (string) Str::uuid());
        $this->assertSame($stripe->id, $first['attempt']->payment_provider_configuration_id);
        $this->assertNull($invoice->fresh()->electronic_payment_provider);
        app(PaymentWorkflow::class)->expire($first['attempt'], $admin);

        $stripe->update(['enabled' => false]);
        $square->update(['enabled' => true]);
        $second = app(PaymentWorkflow::class)->createCheckout($invoice->fresh(), $admin, 1000, (string) Str::uuid());
        $this->assertSame($square->id, $second['attempt']->payment_provider_configuration_id);

        $response = $this->actingAs($admin)->get('/office/invoices/'.$invoice->id)->assertOk();
        $response->assertDontSee('Electronic payment provider')->assertDontSee('preferred_payment_provider');
        $this->actingAs($admin)->put('/office/invoices/'.$invoice->id.'/payments/provider', ['preferred_payment_provider' => 'stripe'])->assertNotFound();
    }

    public function test_provider_switching_is_blocked_only_by_active_attempt_then_success_establishes_lock(): void
    {
        [$invoice, $admin] = $this->invoiceScenario();
        $stripe = $this->provider($invoice, 'stripe', 'test', 'acct_switch');
        $square = $this->provider($invoice, 'square', 'sandbox', 'MERCHANT-SWITCH', ['location_id' => 'LOC']);
        $invoice->forceFill(['preferred_payment_provider' => 'stripe', 'electronic_payment_provider' => null, 'payment_provider_locked_at' => null])->save();
        $workflow = app(PaymentWorkflow::class);
        $first = $workflow->createCheckout($invoice, $admin, 1000, (string) Str::uuid());
        $this->assertNull($invoice->fresh()->electronic_payment_provider);

        try {
            $workflow->setPreferredProvider($invoice->fresh(), $admin, 'square');
            $this->fail('An active attempt must temporarily block provider changes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preferred_payment_provider', $exception->errors());
        }
        $workflow->expire($first['attempt'], $admin);
        $workflow->setPreferredProvider($invoice->fresh(), $admin, 'square');
        $second = $workflow->createCheckout($invoice->fresh(), $admin, 1000, (string) Str::uuid());
        $workflow->processWebhook($square, [
            'event_id' => 'evt-lock', 'type' => 'payment.updated', 'status' => 'succeeded', 'attempt_id' => $second['attempt']->id,
            'session_id' => null, 'order_id' => null, 'payment_id' => 'payment-lock', 'transaction_id' => 'payment-lock', 'amount_cents' => 1000, 'method' => 'card',
        ], hash('sha256', 'lock'));
        $this->assertSame('square', $invoice->fresh()->electronic_payment_provider);
        $this->assertNotNull($invoice->fresh()->payment_provider_locked_at);
        $this->expectException(ValidationException::class);
        $workflow->setPreferredProvider($invoice->fresh(), $admin, $stripe->provider);
    }

    /** @return array{Invoice, User} */
    private function invoiceScenario(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $admin->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', 'super_admin')->firstOrFail());
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-8640', 'title' => 'Connected webhook fixture', 'priority' => 'normal', 'source' => 'internal', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'timezone' => $location->timezone, 'ticket_visit_number' => 1, 'status' => 'approved']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved', 'diagnosis' => 'Fixture', 'work_performed' => 'Fixture', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'handed_off', 'created_by_id' => $admin->id]);
        $invoice = Invoice::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'service_ticket_id' => $ticket->id, 'billing_handoff_id' => $handoff->id, 'generation' => 1, 'invoice_number' => 'NDT-INV-2026-8640', 'status' => 'issued', 'currency' => 'USD', 'payment_terms' => 'due_on_receipt', 'billing_name' => $customer->display_name, 'seller_name' => 'NewDay Tech', 'subtotal_cents' => 10000, 'total_cents' => 10000, 'creation_token' => (string) Str::uuid(), 'issue_token' => (string) Str::uuid(), 'issued_at' => now(), 'issued_by_id' => $admin->id, 'pdf_status' => 'pending', 'created_by_id' => $admin->id, 'updated_by_id' => $admin->id]);
        $handoff->update(['current_invoice_id' => $invoice->id]);

        return [$invoice, $admin];
    }

    /** @param array<string, mixed> $overrides */
    private function provider(Invoice $invoice, string $provider, string $environment, string $accountId, array $overrides = []): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->create(array_merge([
            'organization_id' => $invoice->organization_id, 'public_id' => (string) Str::uuid(), 'provider' => $provider,
            'environment' => $environment, 'connection_method' => 'oauth', 'oauth_access_token' => 'encrypted-provider-token',
            'enabled' => true, 'connection_status' => 'connected', 'external_account_id' => $accountId, 'payments_enabled' => true,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function attempt(Invoice $invoice, PaymentProviderConfiguration $configuration, string $provider, array $overrides = []): PaymentAttempt
    {
        return PaymentAttempt::query()->create(array_merge([
            'organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id, 'payment_provider_configuration_id' => $configuration->id,
            'provider' => $provider, 'amount_cents' => 4000, 'status' => 'processing', 'idempotency_key' => (string) Str::uuid(),
            'return_token_hash' => hash('sha256', (string) Str::uuid()), 'initiated_by_id' => $invoice->issued_by_id,
        ], $overrides));
    }
}
