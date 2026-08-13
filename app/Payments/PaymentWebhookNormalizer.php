<?php

namespace App\Payments;

class PaymentWebhookNormalizer
{
    /** @param array<string, mixed> $event @return array<string, mixed> */
    public static function square(array $event): array
    {
        $payment = data_get($event, 'data.object.payment', []);
        $refund = data_get($event, 'data.object.refund', []);
        $type = (string) ($event['type'] ?? 'unknown');
        $status = str_contains($type, 'refund')
            ? (strtoupper((string) ($refund['status'] ?? '')) === 'COMPLETED' ? 'refunded' : 'processing')
            : match (strtoupper((string) ($payment['status'] ?? ''))) {
                'COMPLETED' => 'succeeded',
                'FAILED', 'CANCELED' => 'failed',
                default => 'processing',
            };

        return [
            'event_id' => (string) ($event['event_id'] ?? ''), 'type' => $type, 'status' => $status,
            'session_id' => null, 'order_id' => $payment['order_id'] ?? null, 'payment_id' => $payment['id'] ?? null,
            'transaction_id' => $refund['id'] ?? ($payment['id'] ?? null),
            'amount_cents' => data_get($refund ?: $payment, 'amount_money.amount'), 'method' => 'card', 'attempt_id' => null,
        ];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public static function stripe(array $event): array
    {
        $object = data_get($event, 'data.object', []);
        $attemptId = data_get($object, 'metadata.payment_attempt_id');
        $status = match ((string) ($event['type'] ?? '')) {
            'checkout.session.completed', 'payment_intent.succeeded' => 'succeeded',
            'checkout.session.expired' => 'expired',
            'payment_intent.payment_failed' => 'failed',
            'refund.updated', 'charge.refunded' => 'refunded',
            default => 'ignored',
        };

        return [
            'event_id' => (string) ($event['id'] ?? ''), 'type' => (string) ($event['type'] ?? 'unknown'), 'status' => $status,
            'session_id' => ($object['object'] ?? null) === 'checkout.session' ? ($object['id'] ?? null) : null,
            'order_id' => null, 'payment_id' => $object['payment_intent'] ?? ($object['id'] ?? null),
            'transaction_id' => $object['id'] ?? null,
            'amount_cents' => $object['amount_total'] ?? ($object['amount_received'] ?? ($object['amount'] ?? null)),
            'method' => 'card', 'attempt_id' => $attemptId ? (int) $attemptId : null,
        ];
    }
}
