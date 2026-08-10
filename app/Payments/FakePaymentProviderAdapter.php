<?php

namespace App\Payments;

use App\Contracts\PaymentProviderAdapter;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentTransaction;

class FakePaymentProviderAdapter implements PaymentProviderAdapter
{
    public function testConnection(PaymentProviderConfiguration $configuration): array
    {
        return ['account_id' => 'fake-'.$configuration->provider];
    }

    public function createCheckout(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt, string $returnUrl): array
    {
        return ['session_id' => 'fake_'.$attempt->idempotency_key, 'order_id' => 'order_'.$attempt->id, 'url' => 'https://checkout.example.test/'.$attempt->id, 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function retrieve(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): array
    {
        return ['status' => $attempt->status, 'payment_id' => $attempt->provider_payment_id, 'amount_cents' => $attempt->amount_cents, 'method' => 'card'];
    }

    public function expire(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): void {}

    public function refund(PaymentProviderConfiguration $configuration, PaymentTransaction $payment, int $amountCents, string $idempotencyKey): array
    {
        return ['status' => 'succeeded', 'transaction_id' => 'refund_'.$idempotencyKey];
    }

    public function parseWebhook(PaymentProviderConfiguration $configuration, string $rawBody, array $headers, string $notificationUrl): array
    {
        $data = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        if (($headers['x-fake-signature'] ?? null) !== hash_hmac('sha256', $rawBody, (string) $configuration->webhook_secret)) {
            abort(400);
        }

        return $data;
    }
}
