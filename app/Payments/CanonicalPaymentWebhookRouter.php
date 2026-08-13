<?php

namespace App\Payments;

use App\Models\PaymentProviderConfiguration;
use Square\Utils\WebhooksHelper;
use Stripe\Webhook;

class CanonicalPaymentWebhookRouter
{
    /** @return array{configuration: PaymentProviderConfiguration, event: array<string, mixed>} */
    public function route(string $provider, string $rawBody, array $headers, string $notificationUrl): array
    {
        return match ($provider) {
            'square' => $this->square($rawBody, $headers, $notificationUrl),
            'stripe' => $this->stripe($rawBody, $headers),
            default => throw new \RuntimeException('unsupported_payment_provider'),
        };
    }

    /** @return array{configuration: PaymentProviderConfiguration, event: array<string, mixed>} */
    private function square(string $rawBody, array $headers, string $notificationUrl): array
    {
        $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        $environment = collect(['sandbox', 'production'])->first(function (string $environment) use ($rawBody, $headers, $notificationUrl): bool {
            $secret = (string) config("payments.connections.square.{$environment}.webhook_signature_key");

            return $secret !== '' && WebhooksHelper::verifySignature($rawBody, (string) ($headers['x-square-hmacsha256-signature'] ?? ''), $secret, $notificationUrl);
        });
        if (! $environment) {
            throw new \RuntimeException('square_signature_invalid');
        }
        $merchantId = (string) ($payload['merchant_id'] ?? '');
        if ($merchantId === '') {
            throw new \RuntimeException('square_merchant_missing');
        }
        $configurations = PaymentProviderConfiguration::query()
            ->where('provider', 'square')->where('connection_method', 'oauth')->where('environment', $environment)
            ->where('external_account_id', $merchantId)->limit(2)->get();
        if ($configurations->count() !== 1) {
            throw new \RuntimeException('square_merchant_unknown');
        }
        $configuration = $configurations->first();
        $event = PaymentWebhookNormalizer::square($payload);
        if ($event['event_id'] === '') {
            throw new \RuntimeException('square_event_id_missing');
        }

        return ['configuration' => $configuration, 'event' => $event];
    }

    /** @return array{configuration: PaymentProviderConfiguration, event: array<string, mixed>} */
    private function stripe(string $rawBody, array $headers): array
    {
        $verified = null;
        foreach (['test', 'live'] as $environment) {
            $secret = (string) config("payments.connections.stripe.{$environment}.webhook_secret");
            if ($secret === '') {
                continue;
            }
            try {
                $candidate = Webhook::constructEvent($rawBody, (string) ($headers['stripe-signature'] ?? ''), $secret);
                $verified = ['environment' => $environment, 'event' => $candidate];
                break;
            } catch (\Throwable) {
                // Try the other configured canonical endpoint secret.
            }
        }
        if (! $verified) {
            throw new \RuntimeException('stripe_signature_invalid');
        }
        $event = json_decode(json_encode($verified['event'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $expectedEnvironment = ($event['livemode'] ?? false) ? 'live' : 'test';
        if ($verified['environment'] !== $expectedEnvironment) {
            throw new \RuntimeException('stripe_environment_mismatch');
        }
        $accountId = (string) ($event['account'] ?? '');
        if (! str_starts_with($accountId, 'acct_')) {
            throw new \RuntimeException('stripe_account_missing');
        }
        $configurations = PaymentProviderConfiguration::query()
            ->where('provider', 'stripe')->where('connection_method', 'oauth')->where('environment', $expectedEnvironment)
            ->where('external_account_id', $accountId)->limit(2)->get();
        if ($configurations->count() !== 1) {
            throw new \RuntimeException('stripe_account_unknown');
        }
        $configuration = $configurations->first();
        $normalized = PaymentWebhookNormalizer::stripe($event);
        if ($normalized['event_id'] === '') {
            throw new \RuntimeException('stripe_event_id_missing');
        }

        return ['configuration' => $configuration, 'event' => $normalized];
    }
}
