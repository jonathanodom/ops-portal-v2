<?php

namespace App\Payments;

use App\Contracts\PaymentProviderAdapter;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\PaymentTransaction;
use Square\Checkout\PaymentLinks\Requests\CreatePaymentLinkRequest;
use Square\Checkout\PaymentLinks\Requests\DeletePaymentLinksRequest;
use Square\Environments;
use Square\Payments\Requests\ListPaymentsRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\SquareClient;
use Square\Types\CheckoutOptions;
use Square\Types\Money;
use Square\Types\QuickPay;
use Square\Utils\WebhooksHelper;

class SquarePaymentProviderAdapter implements PaymentProviderAdapter
{
    public function testConnection(PaymentProviderConfiguration $configuration): array
    {
        $locations = $this->client($configuration)->locations->list()->getLocations() ?? [];
        $location = collect($locations)->first(fn ($item) => $item->getId() === $configuration->location_id) ?? $locations[0] ?? null;
        if (! $location) {
            throw new \RuntimeException('square_location_not_found');
        }

        return ['account_id' => (string) ($location->getMerchantId() ?: $location->getId())];
    }

    public function createCheckout(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt, string $returnUrl): array
    {
        $response = $this->client($configuration)->checkout->paymentLinks->create(new CreatePaymentLinkRequest([
            'idempotencyKey' => $attempt->idempotency_key,
            'description' => 'Invoice '.$attempt->invoice->invoice_number,
            'quickPay' => new QuickPay(['name' => 'Invoice '.$attempt->invoice->invoice_number, 'priceMoney' => new Money(['amount' => $attempt->amount_cents, 'currency' => 'USD']), 'locationId' => (string) $configuration->location_id]),
            'checkoutOptions' => new CheckoutOptions(['redirectUrl' => $returnUrl, 'askForShippingAddress' => false]),
            'paymentNote' => 'Payment attempt '.$attempt->id,
        ]));
        $link = $response->getPaymentLink();
        if (! $link?->getUrl()) {
            throw new \RuntimeException('square_checkout_unavailable');
        }

        return ['session_id' => (string) $link->getId(), 'order_id' => $link->getOrderId(), 'url' => $link->getUrl(), 'expires_at' => null];
    }

    public function retrieve(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): array
    {
        foreach ($this->client($configuration)->payments->list(new ListPaymentsRequest(['beginTime' => $attempt->created_at->copy()->subDay()->toIso8601String(), 'locationId' => $configuration->location_id, 'total' => $attempt->amount_cents, 'limit' => 100])) as $payment) {
            if ($payment->getOrderId() !== $attempt->provider_order_id) {
                continue;
            }
            $status = match ($payment->getStatus()) {
                'COMPLETED' => 'succeeded', 'FAILED','CANCELED' => 'failed', default => 'processing'
            };

            return ['status' => $status, 'payment_id' => $payment->getId(), 'amount_cents' => $payment->getAmountMoney()?->getAmount(), 'method' => 'card'];
        }

        return ['status' => $attempt->expires_at?->isPast() ? 'expired' : 'processing', 'payment_id' => null, 'amount_cents' => null, 'method' => null];
    }

    public function expire(PaymentProviderConfiguration $configuration, PaymentAttempt $attempt): void
    {
        if ($attempt->provider_session_id) {
            $this->client($configuration)->checkout->paymentLinks->delete(new DeletePaymentLinksRequest(['id' => $attempt->provider_session_id]));
        }
    }

    public function refund(PaymentProviderConfiguration $configuration, PaymentTransaction $payment, int $amountCents, string $idempotencyKey): array
    {
        $response = $this->client($configuration)->refunds->refundPayment(new RefundPaymentRequest(['idempotencyKey' => substr($idempotencyKey, 0, 45), 'amountMoney' => new Money(['amount' => $amountCents, 'currency' => 'USD']), 'paymentId' => $payment->provider_transaction_id]));
        $refund = $response->getRefund();

        return ['status' => $refund?->getStatus() === 'COMPLETED' ? 'succeeded' : 'pending', 'transaction_id' => (string) $refund?->getId()];
    }

    public function parseWebhook(PaymentProviderConfiguration $configuration, string $rawBody, array $headers, string $notificationUrl): array
    {
        if (! WebhooksHelper::verifySignature($rawBody, (string) ($headers['x-square-hmacsha256-signature'] ?? ''), (string) $configuration->webhook_secret, $notificationUrl)) {
            abort(400, 'Invalid signature.');
        }
        $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $payment = data_get($event, 'data.object.payment', []);
        $refund = data_get($event, 'data.object.refund', []);
        $type = (string) ($event['type'] ?? 'unknown');
        $status = str_contains($type, 'refund') ? (strtoupper((string) ($refund['status'] ?? '')) === 'COMPLETED' ? 'refunded' : 'processing') : (strtoupper((string) ($payment['status'] ?? '')) === 'COMPLETED' ? 'succeeded' : (strtoupper((string) ($payment['status'] ?? '')) === 'FAILED' ? 'failed' : 'processing'));

        return ['event_id' => (string) ($event['event_id'] ?? ''), 'type' => $type, 'status' => $status, 'session_id' => null, 'order_id' => $payment['order_id'] ?? null, 'payment_id' => $payment['id'] ?? null, 'transaction_id' => $refund['id'] ?? ($payment['id'] ?? null), 'amount_cents' => data_get($refund ?: $payment, 'amount_money.amount'), 'method' => 'card', 'attempt_id' => null];
    }

    private function client(PaymentProviderConfiguration $configuration): SquareClient
    {
        return new SquareClient((string) $configuration->api_secret, options: ['baseUrl' => $configuration->environment === 'production' ? Environments::Production->value : Environments::Sandbox->value]);
    }
}
