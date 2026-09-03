<?php

namespace App\Domain\Notifications;

use App\Jobs\DeliverPortalNotificationEmail;
use App\Models\PortalNotificationEvent;
use App\Models\PortalNotificationRecipient;
use Illuminate\Support\Facades\DB;

final class StaffNotificationEmailChannel
{
    public function queue(PortalNotificationEvent $event): void
    {
        $recipientIds = $event->recipients()
            ->whereJsonContains('channels', 'email')
            ->whereNull('email_queued_at')
            ->whereNull('email_sent_at')
            ->whereHas('user', fn ($query) => $query
                ->where('status', 'active')
                ->whereNotNull('email')
                ->where('email', '<>', ''))
            ->pluck('id');

        foreach ($recipientIds as $recipientId) {
            DB::transaction(function () use ($recipientId): void {
                $recipient = PortalNotificationRecipient::query()->lockForUpdate()->findOrFail($recipientId);
                if ($recipient->email_queued_at !== null || $recipient->email_sent_at !== null) {
                    return;
                }

                $recipient->update(['email_queued_at' => now(), 'email_failed_at' => null]);
                DeliverPortalNotificationEmail::dispatch($recipient->id)->afterCommit();
            });
        }
    }
}
