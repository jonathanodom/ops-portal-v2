<?php

namespace App\Payments;

use App\Contracts\PaymentProviderAdapter;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentTransaction;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripePaymentProviderAdapter implements PaymentProviderAdapter
{
    public function testConnection(PaymentProviderConfiguration $configuration): array
    {
        $account = $this->client($configuration)->accounts->retrieve();

        return ['account_id' => (string) $account->id];
    }

    public function createCheckout(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt, string $returnUrl): array
    {
        $session = $this->client($configuration)->checkout->sessions->create([
            'mode' => 'payment',
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'client_reference_id' => (string) $attempt->id,
            'customer_email' => $attempt->invoice->billing_email,
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => ['currency' => 'usd', 'unit_amount' => $attempt->amount_cents, 'product_data' => ['name' => 'Invoice '.$attempt->invoice->invoice_number]],
                'quantity' => 1,
            ]],
            'metadata' => ['payment_attempt_id' => (string) $attempt->id],
            'payment_intent_data' => ['metadata' => ['payment_attempt_id' => (string) $attempt->id]],
            'expires_at' => now()->addHour()->timestamp,
        ], ['idempotency_key' => $attempt->idempotency_key]);

        return ['session_id' => $session->id, 'order_id' => null, 'url' => $session->url, 'expires_at' => date(DATE_ATOM, $session->expires_at)];
    }

    public function retrieve(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): array
    {
        $session = $this->client($configuration)->checkout->sessions->retrieve((string) $attempt->provider_session_id, []);
        $status = $session->payment_status === 'paid' ? 'succeeded' : ($session->status === 'expired' ? 'expired' : 'processing');

        return ['status' => $status, 'payment_id' => is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent?->id, 'amount_cents' => $session->amount_total, 'method' => 'card'];
    }

    public function expire(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): void
    {
        $this->client($configuration)->checkout->sessions->expire((string) $attempt->provider_session_id);
    }

    public function refund(PaymentProviderConfiguration $configuration, PaymentTransaction $payment, int $amountCents, string $idempotencyKey): array
    {
        $refund = $this->client($configuration)->refunds->create(['payment_intent' => $payment->provider_transaction_id, 'amount' => $amountCents], ['idempotency_key' => $idempotencyKey]);

        return ['status' => $refund->status === 'succeeded' ? 'succeeded' : 'pending', 'transaction_id' => $refund->id];
    }

    public function parseWebhook(PaymentProviderConfiguration $configuration, string $rawBody, array $headers, string $notificationUrl): array
    {
        $event = Webhook::constructEvent($rawBody, (string) ($headers['stripe-signature'] ?? ''), (string) $configuration->webhook_secret);
        $object = $event->data->object;
        $attemptId = $object->metadata->payment_attempt_id ?? null;
        $status = match ($event->type) {
            'checkout.session.completed', 'payment_intent.succeeded' => 'succeeded',
            'checkout.session.expired' => 'expired',
            'payment_intent.payment_failed' => 'failed',
            'refund.updated', 'charge.refunded' => 'refunded',
            default => 'ignored',
        };

        return [
            'event_id' => $event->id, 'type' => $event->type, 'status' => $status,
            'session_id' => $object->object === 'checkout.session' ? $object->id : null,
            'order_id' => null, 'payment_id' => $object->payment_intent ?? ($object->id ?? null),
            'transaction_id' => $object->id ?? null, 'amount_cents' => $object->amount_total ?? ($object->amount_received ?? ($object->amount ?? null)),
            'method' => 'card', 'attempt_id' => $attemptId ? (int) $attemptId : null,
        ];
    }

    private function client(PaymentProviderConfiguration $configuration): StripeClient
    {
        return new StripeClient((string) $configuration->api_secret);
    }
}
