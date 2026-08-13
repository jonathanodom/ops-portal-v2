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
        $account = $configuration->connection_method === 'oauth'
            ? $this->client($configuration)->accounts->retrieve((string) $configuration->external_account_id, [])
            : $this->client($configuration)->accounts->retrieve();

        return ['account_id' => (string) $account->id, 'account_name' => $account->business_profile?->name ?? $account->company?->name];
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
        ], $this->requestOptions($configuration, ['idempotency_key' => $attempt->idempotency_key]));

        return ['session_id' => $session->id, 'order_id' => null, 'url' => $session->url, 'expires_at' => date(DATE_ATOM, $session->expires_at)];
    }

    public function retrieve(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): array
    {
        $session = $this->client($configuration)->checkout->sessions->retrieve((string) $attempt->provider_session_id, [], $this->requestOptions($configuration));
        $status = $session->payment_status === 'paid' ? 'succeeded' : ($session->status === 'expired' ? 'expired' : 'processing');

        return ['status' => $status, 'payment_id' => is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent?->id, 'amount_cents' => $session->amount_total, 'method' => 'card'];
    }

    public function expire(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): void
    {
        $this->client($configuration)->checkout->sessions->expire((string) $attempt->provider_session_id, [], $this->requestOptions($configuration));
    }

    public function refund(PaymentProviderConfiguration $configuration, PaymentTransaction $payment, int $amountCents, string $idempotencyKey): array
    {
        $refund = $this->client($configuration)->refunds->create(['payment_intent' => $payment->provider_transaction_id, 'amount' => $amountCents], $this->requestOptions($configuration, ['idempotency_key' => $idempotencyKey]));

        return ['status' => $refund->status === 'succeeded' ? 'succeeded' : 'pending', 'transaction_id' => $refund->id];
    }

    public function parseWebhook(PaymentProviderConfiguration $configuration, string $rawBody, array $headers, string $notificationUrl): array
    {
        $event = Webhook::constructEvent($rawBody, (string) ($headers['stripe-signature'] ?? ''), (string) $configuration->webhook_secret);

        return PaymentWebhookNormalizer::stripe(json_decode(json_encode($event, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }

    private function client(PaymentProviderConfiguration $configuration): StripeClient
    {
        $secret = $configuration->connection_method === 'oauth'
            ? config("payments.connections.stripe.{$configuration->environment}.platform_secret")
            : $configuration->api_secret;

        return new StripeClient((string) $secret);
    }

    /** @param array<string, string> $options @return array<string, string> */
    private function requestOptions(PaymentProviderConfiguration $configuration, array $options = []): array
    {
        if ($configuration->connection_method === 'oauth') {
            $options['stripe_account'] = (string) $configuration->external_account_id;
        }

        return $options;
    }
}
