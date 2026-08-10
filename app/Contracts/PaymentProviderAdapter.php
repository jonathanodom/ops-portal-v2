<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentTransaction;

interface PaymentProviderAdapter
{
    /** @return array{account_id:string} */
    public function testConnection(PaymentProviderConfiguration $configuration): array;

    /** @return array{session_id:string,order_id:?string,url:string,expires_at:?string} */
    public function createCheckout(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt, string $returnUrl): array;

    /** @return array{status:string,payment_id:?string,amount_cents:?int,method:?string} */
    public function retrieve(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): array;

    public function expire(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): void;

    /** @return array{status:string,transaction_id:string} */
    public function refund(PaymentProviderConfiguration $configuration, PaymentTransaction $payment, int $amountCents, string $idempotencyKey): array;

    /** @param array<string, string|null> $headers
     * @return array{event_id:string,type:string,status:string,session_id:?string,order_id:?string,payment_id:?string,transaction_id:?string,amount_cents:?int,method:?string}
     */
    public function parseWebhook(PaymentProviderConfiguration $configuration, string $rawBody, array $headers, string $notificationUrl): array;
}
