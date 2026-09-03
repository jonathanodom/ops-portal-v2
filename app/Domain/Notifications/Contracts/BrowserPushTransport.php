<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\PushDeliveryResult;
use App\Models\BrowserPushSubscription;

interface BrowserPushTransport
{
    /** @param array<string, mixed> $payload */
    public function send(BrowserPushSubscription $subscription, array $payload): PushDeliveryResult;
}
