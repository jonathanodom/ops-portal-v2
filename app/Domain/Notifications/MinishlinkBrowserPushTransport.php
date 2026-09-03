<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Contracts\BrowserPushTransport;
use App\Models\BrowserPushSubscription;
use LogicException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class MinishlinkBrowserPushTransport implements BrowserPushTransport
{
    public function send(BrowserPushSubscription $subscription, array $payload): PushDeliveryResult
    {
        if (! $this->configured()) {
            throw new LogicException('Browser push is not configured.');
        }

        $client = new WebPush(['VAPID' => [
            'subject' => (string) config('services.web_push.vapid_subject'),
            'publicKey' => (string) config('services.web_push.vapid_public_key'),
            'privateKey' => (string) config('services.web_push.vapid_private_key'),
        ]]);
        $report = $client->sendOneNotification(
            Subscription::create($subscription->subscriptionPayload()),
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        if ($report->isSuccess()) {
            return new PushDeliveryResult(true);
        }

        $status = $report->getResponse()?->getStatusCode();

        return new PushDeliveryResult(
            false,
            $report->isSubscriptionExpired(),
            $status === null ? 'transport_failure' : 'http_'.$status,
        );
    }

    private function configured(): bool
    {
        return filled(config('services.web_push.vapid_subject'))
            && filled(config('services.web_push.vapid_public_key'))
            && filled(config('services.web_push.vapid_private_key'));
    }
}
