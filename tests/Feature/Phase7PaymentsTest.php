<?php

namespace Tests\Feature;

use App\Domain\PaymentWorkflow;
use App\Jobs\RenderPaymentReceiptPdf;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderConfiguration;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Payments\SquarePaymentProviderAdapter;
use App\Payments\StripePaymentProviderAdapter;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\SignatureVerificationException;
use Tests\TestCase;

class Phase7PaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        config(['payments.fake' => true]);
    }

    public function test_provider_credentials_are_encrypted_hidden_and_super_admin_only(): void
    {
        [$invoice, $admin] = $this->invoiceScenario();
        $secret = 'sk_test_secret_that_must_not_render';
        $this->actingAs($admin)->put('/office/settings/billing/payments/stripe', ['environment' => 'test', 'api_secret' => $secret, 'webhook_secret' => 'whsec_test_secret'])->assertRedirect();
        $configuration = PaymentProviderConfiguration::query()->firstOrFail();
        $this->assertSame($secret, $configuration->api_secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('payment_provider_configurations')->value('api_secret'));
        $this->actingAs($admin)->get('/office/settings/billing')->assertOk()->assertDontSee($secret)->assertSee($configuration->credential_fingerprint);

        [$billing] = $this->userWithRole('billing', $invoice->organization);
        $this->actingAs($billing)->get('/office/settings/billing')->assertOk()->assertSee('You can view readiness')->assertDontSee('Save Stripe credentials');
        $this->actingAs($billing)->put('/office/settings/billing/payments/stripe', ['environment' => 'test', 'api_secret' => 'forged-secret'])->assertForbidden();
    }

    public function test_live_activation_is_blocked_outside_production_and_clearing_is_guarded(): void
    {
        [, $admin, $configuration] = $this->invoiceScenario();
        $configuration->update(['environment' => 'live']);
        $this->actingAs($admin)->post('/office/settings/billing/payments/stripe/toggle', ['enabled' => 1, 'confirm_live' => 1])->assertSessionHasErrors('enabled');
        $configuration->update(['enabled' => false]);
        $this->actingAs($admin)->delete('/office/settings/billing/payments/stripe', ['confirmation' => 'wrong'])->assertSessionHasErrors('confirmation');
        $this->actingAs($admin)->delete('/office/settings/billing/payments/stripe', ['confirmation' => 'CLEAR STRIPE'])->assertRedirect();
        $this->assertNull($configuration->fresh()->api_secret);
    }

    public function test_official_webhook_verifiers_parse_safe_events_and_reject_tampering(): void
    {
        [$invoice, , $configuration] = $this->invoiceScenario();
        $square = $configuration->replicate();
        $square->forceFill(['provider' => 'square', 'environment' => 'sandbox', 'public_id' => (string) Str::uuid(), 'webhook_secret' => 'square-signature-secret', 'location_id' => 'LOCATION'])->save();
        $squareBody = json_encode(['event_id' => 'square-event', 'type' => 'payment.updated', 'data' => ['object' => ['payment' => ['id' => 'payment-1', 'order_id' => 'order-1', 'status' => 'COMPLETED', 'amount_money' => ['amount' => 2500]]]]], JSON_THROW_ON_ERROR);
        $url = 'https://portal.example.test/webhooks/payments/square/'.$square->public_id;
        $signature = base64_encode(hash_hmac('sha256', $url.$squareBody, $square->webhook_secret, true));
        $event = app(SquarePaymentProviderAdapter::class)->parseWebhook($square, $squareBody, ['x-square-hmacsha256-signature' => $signature], $url);
        $this->assertSame('succeeded', $event['status']);
        $this->assertSame(2500, $event['amount_cents']);

        $stripeBody = json_encode(['id' => 'evt_stripe', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test', 'object' => 'checkout.session', 'metadata' => ['payment_attempt_id' => '12'], 'payment_intent' => 'pi_test', 'amount_total' => 2500]]], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $stripeSignature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$stripeBody, $configuration->webhook_secret);
        $stripeEvent = app(StripePaymentProviderAdapter::class)->parseWebhook($configuration, $stripeBody, ['stripe-signature' => $stripeSignature], '');
        $this->assertSame('succeeded', $stripeEvent['status']);
        $this->assertSame(12, $stripeEvent['attempt_id']);
        $this->expectException(SignatureVerificationException::class);
        app(StripePaymentProviderAdapter::class)->parseWebhook($configuration, $stripeBody.' ', ['stripe-signature' => $stripeSignature], '');
    }

    public function test_checkout_is_idempotent_locks_provider_and_only_authoritative_result_creates_payment(): void
    {
        Queue::fake();
        [$invoice, $admin, $configuration] = $this->invoiceScenario();
        $workflow = app(PaymentWorkflow::class);
        $token = (string) Str::uuid();
        $result = $workflow->createCheckout($invoice, $admin, 4000, $token);
        $retry = $workflow->createCheckout($invoice->fresh(), $admin, 4000, $token);
        $this->assertSame($result['attempt']->id, $retry['attempt']->id);
        $this->assertSame('stripe', $invoice->fresh()->electronic_payment_provider);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->expectException(ValidationException::class);
        $workflow->recordManual($invoice->fresh(), $admin, 'cash', 1000, now(), null, (string) Str::uuid());
    }

    public function test_verified_webhook_converges_to_one_payment_and_receipt(): void
    {
        Queue::fake();
        [$invoice, $admin, $configuration] = $this->invoiceScenario();
        $result = app(PaymentWorkflow::class)->createCheckout($invoice, $admin, 4000, (string) Str::uuid());
        $attempt = $result['attempt'];
        $event = ['event_id' => 'evt_1', 'type' => 'checkout.session.completed', 'status' => 'succeeded', 'session_id' => $attempt->provider_session_id, 'order_id' => null, 'payment_id' => 'pi_123', 'transaction_id' => 'pi_123', 'amount_cents' => 4000, 'method' => 'card', 'attempt_id' => $attempt->id];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, $configuration->webhook_secret);
        $url = "/webhooks/payments/stripe/{$configuration->public_id}";
        $this->withHeader('X-Fake-Signature', $signature)->postJson($url, $event)->assertOk();
        $this->withHeader('X-Fake-Signature', $signature)->postJson($url, $event)->assertOk();
        $this->assertDatabaseCount('payment_transactions', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertSame('partially_paid', $invoice->fresh()->paymentState());
        Queue::assertPushed(RenderPaymentReceiptPdf::class);
        $this->withHeader('X-Fake-Signature', 'invalid')->postJson($url, $event)->assertBadRequest();
    }

    public function test_partial_cash_check_reversal_and_public_receipt_are_immutable_and_safe(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $workflow = app(PaymentWorkflow::class);
        $cash = $workflow->recordManual($invoice, $admin, 'cash', 3000, now(), null, (string) Str::uuid());
        $check = $workflow->recordManual($invoice->fresh(), $admin, 'check', 2000, now(), 'CHK-100', (string) Str::uuid());
        $this->assertSame(5000, $invoice->fresh()->balanceCents());
        $workflow->reverseManual($check, $admin, 500, 'Correction kept privately', (string) Str::uuid());
        $this->assertSame(5500, $invoice->fresh()->balanceCents());
        $result = $workflow->rotateReceiptToken($cash->receipt, $admin);
        $url = route('payments.receipts.show', ['receipt' => $cash->receipt, 'token' => $result['token']]);
        $this->get($url)->assertOk()->assertSee($invoice->invoice_number)->assertDontSee('Correction kept privately')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('payments.receipts.show', ['receipt' => $cash->receipt, 'token' => 'invalid']))->assertNotFound();
    }

    public function test_zero_invoice_needs_no_provider_and_payment_routes_are_organization_scoped(): void
    {
        [$invoice,$admin] = $this->invoiceScenario();
        $invoice->forceFill(['total_cents' => 0, 'preferred_payment_provider' => null])->save();
        $this->assertNull($invoice->preferred_payment_provider);
        [$outsider] = $this->userWithRole('super_admin');
        $this->actingAs($outsider)->post("/office/invoices/{$invoice->id}/payments/manual", ['method' => 'cash', 'amount' => '1.00', 'received_at' => now()->format('Y-m-d H:i'), 'idempotency_key' => (string) Str::uuid()])->assertNotFound();
    }

    /** @return array{Invoice,User,PaymentProviderConfiguration} */
    private function invoiceScenario(): array
    {
        $organization = Organization::factory()->create(['name' => 'NewDay Tech', 'legal_name' => 'NewDay Tech LLC', 'email' => 'billing@example.test', 'phone' => '555-0100', 'address_line_1' => '100 Service Way', 'city' => 'Dallas', 'state' => 'TX', 'postal_code' => '75001', 'timezone' => 'America/Chicago']);
        [$admin] = $this->userWithRole('super_admin', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-7001', 'title' => 'Payment fixture', 'priority' => 'normal', 'source' => 'phone', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'timezone' => $location->timezone, 'status' => 'approved']);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 1, 'outcome' => 'resolved', 'diagnosis' => 'Fixture', 'work_performed' => 'Fixture', 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $handoff = BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'handed_off', 'created_by_id' => $admin->id]);
        $invoice = Invoice::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'service_ticket_id' => $ticket->id, 'billing_handoff_id' => $handoff->id, 'generation' => 1, 'invoice_number' => 'NDT-INV-2026-7001', 'status' => 'issued', 'currency' => 'USD', 'payment_terms' => 'due_on_receipt', 'billing_name' => $customer->display_name, 'seller_name' => 'NewDay Tech', 'subtotal_cents' => 10000, 'total_cents' => 10000, 'creation_token' => (string) Str::uuid(), 'issue_token' => (string) Str::uuid(), 'issued_at' => now(), 'issued_by_id' => $admin->id, 'pdf_status' => 'pending', 'created_by_id' => $admin->id, 'updated_by_id' => $admin->id]);
        $configuration = PaymentProviderConfiguration::query()->create(['organization_id' => $organization->id, 'public_id' => (string) Str::uuid(), 'provider' => 'stripe', 'environment' => 'test', 'api_secret' => 'sk_test_fake', 'webhook_secret' => 'whsec_fake', 'credential_fingerprint' => 'TEST00000000', 'enabled' => true, 'connection_status' => 'connected', 'external_account_id' => 'acct_fake']);
        $invoice->forceFill(['preferred_payment_provider' => 'stripe'])->save();
        $handoff->update(['current_invoice_id' => $invoice->id]);

        return [$invoice, $admin, $configuration];
    }

    private function userWithRole(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
