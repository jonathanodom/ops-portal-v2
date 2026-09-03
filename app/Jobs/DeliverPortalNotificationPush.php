<?php

namespace App\Jobs;

use App\Domain\Notifications\Contracts\BrowserPushTransport;
use App\Models\PortalNotificationPushDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DeliverPortalNotificationPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BrowserPushTransport $transport): void
    {
        $delivery = PortalNotificationPushDelivery::query()
            ->with(['recipient.event', 'subscription'])
            ->findOrFail($this->deliveryId);
        if (in_array($delivery->status, ['sent', 'expired'], true)) {
            return;
        }
        if ($delivery->subscription->disabled_at !== null) {
            $delivery->update(['status' => 'expired', 'expired_at' => now(), 'failure_code' => 'subscription_disabled']);

            return;
        }

        $event = $delivery->recipient->event;
        $result = $transport->send($delivery->subscription, [
            'title' => $event->metadata['push_title'] ?? $event->title,
            'body' => $event->metadata['push_body'] ?? $event->body,
            'url' => $event->action_url ?: '/notifications',
            'notificationId' => $delivery->recipient->id,
        ]);
        if ($result->successful) {
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'failure_code' => null]);

            return;
        }
        if ($result->permanentlyInvalid) {
            DB::transaction(function () use ($delivery, $result): void {
                $delivery->subscription()->lockForUpdate()->update(['disabled_at' => now()]);
                $delivery->update([
                    'status' => 'expired',
                    'expired_at' => now(),
                    'failure_code' => $result->failureCode,
                ]);
            });

            return;
        }

        throw new RuntimeException('Temporary browser push delivery failure.');
    }

    public function failed(Throwable $exception): void
    {
        PortalNotificationPushDelivery::query()
            ->whereKey($this->deliveryId)
            ->whereNotIn('status', ['sent', 'expired'])
            ->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'delivery_failed']);
        Log::error('Portal browser push delivery failed.', [
            'delivery_id' => $this->deliveryId,
            'failure_type' => class_basename($exception),
        ]);
    }
}
