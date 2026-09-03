<?php

namespace App\Jobs;

use App\Mail\StaffPortalNotificationMail;
use App\Models\PortalNotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class DeliverPortalNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $recipientId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $recipient = PortalNotificationRecipient::query()
            ->with(['event', 'user'])
            ->findOrFail($this->recipientId);

        if ($recipient->email_sent_at !== null
            || ! in_array('email', $recipient->channels, true)
            || ! $this->isDeliverable($recipient)) {
            return;
        }

        Mail::to($recipient->user->email)->send(new StaffPortalNotificationMail($recipient->event, $recipient->user));
        $recipient->update(['email_sent_at' => now(), 'email_failed_at' => null]);
    }

    public function failed(Throwable $exception): void
    {
        PortalNotificationRecipient::query()
            ->whereKey($this->recipientId)
            ->whereNull('email_sent_at')
            ->update(['email_failed_at' => now()]);

        Log::error('Portal notification email delivery failed.', [
            'recipient_id' => $this->recipientId,
            'failure_type' => class_basename($exception),
        ]);
    }

    private function isDeliverable(PortalNotificationRecipient $recipient): bool
    {
        if ($recipient->user->status !== 'active' || ! filter_var($recipient->user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return $recipient->user->memberships()
            ->where('organization_id', $recipient->organization_id)
            ->where('status', 'active')
            ->exists();
    }
}
