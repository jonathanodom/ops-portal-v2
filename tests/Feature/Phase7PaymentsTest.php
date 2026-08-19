<?php

namespace Tests\Feature;

use App\Domain\PaymentWorkflow;
use App\Jobs\RenderPaymentReceiptPdf;
use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentTransaction;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Payments\PaymentProviderResolver;
use App\Payments\SquarePaymentProviderAdapter;
use App\Payments\StripePaymentProviderAdapter;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
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

    public function test_legacy_provider_credentials_remain_encrypted_hidden_and_normal_secret_entry_is_closed(): void
    {
        [$invoice, $admin, $configuration] = $this->invoiceScenario();
        $secret = 'sk_test_secret_that_must_not_render';
        $configuration->update(['api_secret' => $secret, 'credential_fingerprint' => strtoupper(substr(hash('sha256', $secret), 0, 12))]);
        $this->assertSame($secret, $configuration->api_secret);
        $this->assertStringNotContainsString($secret, (string) DB::table('payment_provider_configurations')->where('id', $configuration->id)->value('api_secret'));
        $this->actingAs($admin)->get('/office/settings/billing')->assertOk()->assertDontSee($secret)->assertSee($configuration->credential_fingerprint);
        $this->actingAs($admin)->put('/office/settings/billing/payments/stripe', ['environment' => 'test', 'api_secret' => 'forged-secret'])->assertNotFound();

        [$billing] = $this->userWithRole('billing', $invoice->organization);
        $this->actingAs($billing)->get('/office/settings/billing')->assertOk()->assertSee('You can view readiness')->assertDontSee('Secret key');
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

    public function test_checkout_is_idempotent_and_only_authoritative_result_locks_provider_and_creates_payment(): void
    {
        Queue::fake();
        [$invoice, $admin, $configuration] = $this->invoiceScenario();
        $workflow = app(PaymentWorkflow::class);
        $token = (string) Str::uuid();
        $result = $workflow->createCheckout($invoice, $admin, 4000, $token);
        $retry = $workflow->createCheckout($invoice->fresh(), $admin, 4000, $token);
        $this->assertSame($result['attempt']->id, $retry['attempt']->id);
        $this->assertNull($invoice->fresh()->electronic_payment_provider);
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
        $this->assertSame('hosted_checkout', PaymentTransaction::query()->sole()->payment_source);
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

    public function test_issued_invoice_without_selection_uses_sole_ready_provider_and_accepts_cash_and_check(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $invoice->forceFill(['preferred_payment_provider' => null])->save();
        $workflow = app(PaymentWorkflow::class);

        $attempt = $workflow->createCheckout($invoice, $admin, 1000, (string) Str::uuid())['attempt'];
        $this->assertSame('stripe', $attempt->provider);
        $workflow->expire($attempt, $admin);

        $cash = $workflow->recordManual($invoice->fresh(), $admin, 'cash', 1000, now(), null, (string) Str::uuid());
        $check = $workflow->recordManual($invoice->fresh(), $admin, 'check', 1000, now(), 'CHK-8501', (string) Str::uuid());
        $this->assertNotNull($cash->receipt);
        $this->assertNotNull($check->receipt);

        $this->expectException(ValidationException::class);
        $workflow->recordManual($invoice->fresh(), $admin, 'check', 1000, now(), null, (string) Str::uuid());
    }

    public function test_authorized_override_can_select_ready_provider_but_active_attempt_only_temporarily_blocks_switching(): void
    {
        Queue::fake();
        [$invoice, $admin, $stripe] = $this->invoiceScenario();
        $invoice->forceFill(['preferred_payment_provider' => null])->save();
        $square = PaymentProviderConfiguration::query()->create([
            'organization_id' => $invoice->organization_id,
            'public_id' => (string) Str::uuid(),
            'provider' => 'square',
            'environment' => 'sandbox',
            'api_secret' => 'square_fake',
            'webhook_secret' => 'square_webhook',
            'location_id' => 'LOCATION',
            'credential_fingerprint' => 'SQUARE850000',
            'enabled' => true,
            'connection_status' => 'connected',
        ]);
        $workflow = app(PaymentWorkflow::class);

        $workflow->setPreferredProvider($invoice, $admin, 'square');
        $result = $workflow->createCheckout($invoice->fresh(), $admin, 1000, (string) Str::uuid());
        $this->assertSame($square->id, $result['attempt']->payment_provider_configuration_id);
        $this->assertNull($invoice->fresh()->electronic_payment_provider);

        try {
            $workflow->setPreferredProvider($invoice->fresh(), $admin, $stripe->provider);
            $this->fail('Active checkout should temporarily block switching.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preferred_payment_provider', $exception->errors());
        }
        $workflow->expire($result['attempt'], $admin);
        $this->assertSame('stripe', $workflow->setPreferredProvider($invoice->fresh(), $admin, 'stripe')->preferred_payment_provider);
    }

    public function test_issued_invoice_uses_payment_overlays_and_only_admin_sees_provider_override(): void
    {
        [$invoice, $admin] = $this->invoiceScenario();
        PaymentProviderConfiguration::query()->create([
            'organization_id' => $invoice->organization_id,
            'public_id' => (string) Str::uuid(),
            'provider' => 'square',
            'environment' => 'sandbox',
            'api_secret' => 'square_fake',
            'webhook_secret' => 'square_webhook',
            'location_id' => 'LOCATION',
            'credential_fingerprint' => 'SQUARE860000',
            'enabled' => true,
            'connection_status' => 'connected',
        ]);

        $this->actingAs($admin)->get(route('office.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="record-payment-dialog"', false)
            ->assertSee('id="secure-payment-dialog"', false)
            ->assertSee('id="payment-history-dialog"', false)
            ->assertSee('Use Square instead')
            ->assertDontSee('<select name="preferred_payment_provider"', false);

        [$billing] = $this->userWithRole('billing', $invoice->organization);
        $this->actingAs($billing)->get(route('office.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('id="record-payment-dialog"', false)
            ->assertSee('id="secure-payment-dialog"', false)
            ->assertDontSee('Use Square instead');
    }

    public function test_office_secure_payment_creation_reopens_workspace_and_provider_override_obeys_locking(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $square = PaymentProviderConfiguration::query()->create([
            'organization_id' => $invoice->organization_id,
            'public_id' => (string) Str::uuid(),
            'provider' => 'square',
            'environment' => 'sandbox',
            'api_secret' => 'square_fake',
            'webhook_secret' => 'square_webhook',
            'location_id' => 'LOCATION',
            'credential_fingerprint' => 'SQUARE860001',
            'enabled' => true,
            'connection_status' => 'connected',
        ]);

        $this->actingAs($admin)->put(route('office.invoices.payments.provider', $invoice), [
            'payment_form_context' => 'secure',
            'preferred_payment_provider' => 'square',
        ])->assertRedirect()->assertSessionHas('payment_overlay', 'secure');
        $this->assertSame('square', $invoice->fresh()->preferred_payment_provider);

        $response = $this->actingAs($admin)->from(route('office.invoices.show', $invoice))->post(route('office.invoices.payments.checkout', $invoice), [
            'payment_form_context' => 'secure',
            'amount' => '25.00',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $response->assertRedirect(route('office.invoices.show', $invoice))->assertSessionHas('payment_overlay', 'secure');
        $attempt = $invoice->paymentAttempts()->firstOrFail();
        $this->assertSame($square->id, $attempt->payment_provider_configuration_id);

        $this->actingAs($admin)->get(route('office.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Payment link ready')
            ->assertSee('data-auto-open="true"', false)
            ->assertSee(route('office.invoices.payments.qr', [$invoice, $attempt]), false);

        $this->actingAs($admin)->put(route('office.invoices.payments.provider', $invoice), [
            'payment_form_context' => 'secure',
            'preferred_payment_provider' => 'stripe',
        ])->assertSessionHasErrors('preferred_payment_provider');
    }

    public function test_manual_payment_validation_reopens_modal_and_optional_note_remains_internal(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $url = route('office.invoices.show', $invoice);

        $this->actingAs($admin)->from($url)->post(route('office.invoices.payments.manual', $invoice), [
            'payment_form_context' => 'manual',
            'method' => 'check',
            'amount' => '15.00',
            'received_at' => now($invoice->organization->timezone)->format('Y-m-d\TH:i'),
            'reference' => '',
            'note' => 'Customer paid after the service appointment.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect($url)->assertSessionHasErrors('reference');

        $this->get($url)->assertOk()
            ->assertSee('Payment details need attention')
            ->assertSee('id="record-payment-dialog"', false)
            ->assertSee('data-auto-open="true"', false);

        $this->actingAs($admin)->from($url)->post(route('office.invoices.payments.manual', $invoice), [
            'payment_form_context' => 'manual',
            'method' => 'cash',
            'amount' => '15.00',
            'received_at' => now($invoice->organization->timezone)->format('Y-m-d\TH:i'),
            'reference' => '',
            'note' => 'Internal collection note.',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect($url)->assertSessionHas('payment_overlay', 'history');

        $transaction = $invoice->paymentTransactions()->firstOrFail();
        $this->assertSame('Internal collection note.', $transaction->reason);
        $this->get(route('payments.receipts.show', ['receipt' => $transaction->receipt, 'token' => 'invalid']))
            ->assertNotFound()
            ->assertDontSee('Internal collection note.');
    }

    public function test_manual_square_pos_credit_and_debit_payments_store_safe_canonical_provenance(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $url = route('office.invoices.show', $invoice);

        $this->actingAs($admin)->from($url)->post(route('office.invoices.payments.manual', $invoice), [
            'payment_form_context' => 'manual',
            'method' => 'credit_card',
            'payment_source' => 'hosted_checkout',
            'amount' => '25.00',
            'received_at' => now($invoice->organization->timezone)->format('Y-m-d\TH:i'),
            'reference' => 'SQ-POS-1001',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect($url)->assertSessionHas('status', 'Credit Card · Square POS payment recorded.');

        $credit = $invoice->paymentTransactions()->sole();
        $this->assertSame('credit_card', $credit->method);
        $this->assertSame('square_pos', $credit->payment_source);
        $this->assertNull($credit->provider);
        $this->assertNull($credit->payment_attempt_id);
        $this->assertSame('SQ-POS-1001', $credit->manual_reference);
        $this->assertSame(7500, $invoice->fresh()->balanceCents());
        $this->assertNotNull($credit->receipt);

        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertSee('Credit Card &mdash; Square POS', false)
            ->assertSee('Debit Card &mdash; Square POS', false)
            ->assertSee('Ops Portal does not charge the card or verify the Square transaction')
            ->assertSee('Credit Card · Square POS')
            ->assertSee('SQ-POS-1001');

        $debit = app(PaymentWorkflow::class)->recordManual($invoice->fresh(), $admin, 'debit_card', 7500, now(), null, (string) Str::uuid());
        $this->assertSame('debit_card', $debit->method);
        $this->assertSame('square_pos', $debit->payment_source);
        $this->assertNull($debit->provider);
        $this->assertNull($debit->payment_attempt_id);
        $this->assertNull($debit->manual_reference);
        $this->assertSame('paid', $invoice->fresh()->paymentState());
    }

    public function test_manual_square_pos_reversal_never_resolves_a_connected_provider(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $resolver = Mockery::mock(PaymentProviderResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(PaymentProviderResolver::class, $resolver);
        $workflow = app(PaymentWorkflow::class);

        $payment = $workflow->recordManual($invoice, $admin, 'credit_card', 4000, now(), 'SQ-POS-REV-1', (string) Str::uuid());
        $reversal = $workflow->reverseManual($payment, $admin, 1500, 'Corrected after separate Square action.', (string) Str::uuid());

        $this->assertSame('reversal', $reversal->type);
        $this->assertSame('succeeded', $reversal->status);
        $this->assertSame('credit_card', $reversal->method);
        $this->assertSame('square_pos', $reversal->payment_source);
        $this->assertNull($reversal->provider);
        $this->assertSame(7500, $invoice->fresh()->balanceCents());
    }

    public function test_manual_square_pos_receipt_is_labeled_as_an_external_record_and_audit_is_safe(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $payment = app(PaymentWorkflow::class)->recordManual($invoice, $admin, 'debit_card', 2500, now(), 'SQ-POS-SAFE-2', (string) Str::uuid());
        $result = app(PaymentWorkflow::class)->rotateReceiptToken($payment->receipt, $admin);

        $this->get(route('payments.receipts.show', ['receipt' => $payment->receipt, 'token' => $result['token']]))
            ->assertOk()
            ->assertSee('Debit Card · Square POS')
            ->assertSee('SQ-POS-SAFE-2')
            ->assertSee('not a Square-generated receipt')
            ->assertDontSee('card number')
            ->assertDontSee('CVV');

        $event = AuditEvent::query()->where('organization_id', $invoice->organization_id)->where('event_type', 'payment.manual_recorded')->latest('id')->firstOrFail();
        $this->assertSame('debit_card', $event->metadata['method']);
        $this->assertSame('square_pos', $event->metadata['payment_source']);
        $this->assertSame(2500, $event->metadata['amount_cents']);
        $this->assertArrayNotHasKey('reference', $event->metadata);
    }

    public function test_manual_workflow_rejects_unknown_methods_instead_of_accepting_spoofed_sources(): void
    {
        [$invoice, $admin] = $this->invoiceScenario();

        try {
            app(PaymentWorkflow::class)->recordManual($invoice, $admin, 'square', 1000, now(), null, (string) Str::uuid());
            $this->fail('Unknown manual methods must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('method', $exception->errors());
        }

        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_payment_source_migration_backfills_manual_and_hosted_transactions(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $manual = app(PaymentWorkflow::class)->recordManual($invoice, $admin, 'cash', 1000, now(), null, (string) Str::uuid());
        $hosted = PaymentTransaction::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'type' => 'payment',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'method' => 'card',
            'payment_source' => 'hosted_checkout',
            'amount_cents' => 1000,
            'provider_transaction_id' => 'pi_backfill_test',
            'idempotency_key' => (string) Str::uuid(),
            'received_at' => now(),
            'confirmed_at' => now(),
            'recorded_by_id' => $admin->id,
        ]);
        DB::table('payment_transactions')->whereIn('id', [$manual->id, $hosted->id])->update(['payment_source' => null]);

        $migration = require database_path('migrations/2026_08_19_010000_add_payment_source_to_payment_transactions.php');
        $migration->up();

        $this->assertSame('manual', $manual->fresh()->payment_source);
        $this->assertSame('hosted_checkout', $hosted->fresh()->payment_source);
        $columns = Schema::getColumnListing('payment_transactions');
        $this->assertContains('payment_source', $columns);
        $this->assertEmpty(array_intersect($columns, ['card_number', 'pan', 'cvv', 'cvc', 'expiry', 'pin', 'track_data', 'emv_data']));
    }

    public function test_connected_provider_refunds_remain_provider_owned_and_inherit_hosted_source(): void
    {
        Queue::fake();
        [$invoice, $admin] = $this->invoiceScenario();
        $payment = PaymentTransaction::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->id,
            'type' => 'payment',
            'status' => 'succeeded',
            'provider' => 'stripe',
            'method' => 'card',
            'payment_source' => 'hosted_checkout',
            'amount_cents' => 4000,
            'provider_transaction_id' => 'pi_provider_refund_test',
            'idempotency_key' => (string) Str::uuid(),
            'received_at' => now(),
            'confirmed_at' => now(),
            'recorded_by_id' => $admin->id,
        ]);

        $refund = app(PaymentWorkflow::class)->refund($payment, $admin, 1000, 'Provider refund regression.', (string) Str::uuid());

        $this->assertSame('refund', $refund->type);
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame('stripe', $refund->provider);
        $this->assertSame('hosted_checkout', $refund->payment_source);
        $this->assertStringStartsWith('refund_', $refund->provider_transaction_id);
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
