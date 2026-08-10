<?php

namespace App\Payments;

use App\Contracts\PaymentProviderAdapter;
use App\Models\PaymentProviderConfiguration;
use InvalidArgumentException;

class PaymentProviderResolver
{
    public function resolve(PaymentProviderConfiguration $configuration): PaymentProviderAdapter
    {
        if (app()->environment('testing') && config('payments.fake')) {
            return app(FakePaymentProviderAdapter::class);
        }

        return match ($configuration->provider) {
            'square' => app(SquarePaymentProviderAdapter::class),
            'stripe' => app(StripePaymentProviderAdapter::class),
            default => throw new InvalidArgumentException('Unsupported payment provider.'),
        };
    }
}
