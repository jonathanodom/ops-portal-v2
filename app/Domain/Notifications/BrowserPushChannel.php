<?php

namespace App\Domain\Notifications;

use App\Jobs\DeliverPortalNotificationPush;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationPushDelivery;
use Illuminate\Support\Facades\DB;

final class BrowserPushChannel
{
    public function queue(PortalNotificationEvent $event): void
    {
        $event->recipients()
            ->whereJsonContains('channels', 'push')
            ->with(['user.browserPushSubscriptions' => fn ($query) => $query
                ->forOrganization($event->organization_id)
                ->whereNull('disabled_at')])
            ->get()
            ->each(function ($recipient): void {
                foreach ($recipient->user->browserPushSubscriptions as $subscription) {
                    $delivery = PortalNotificationPushDelivery::query()->firstOrCreate(
                        [
                            'portal_notification_recipient_id' => $recipient->id,
                            'browser_push_subscription_id' => $subscription->id,
                        ],
                        [
                            'organization_id' => $recipient->organization_id,
                            'status' => 'queued',
                            'queued_at' => now(),
                        ],
                    );
                    if ($delivery->wasRecentlyCreated) {
                        DB::afterCommit(fn () => DeliverPortalNotificationPush::dispatch($delivery->id));
                    }
                }
            });
    }
}
