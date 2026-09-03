<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'portal_notification_recipient_id', 'browser_push_subscription_id',
    'status', 'queued_at', 'sent_at', 'failed_at', 'expired_at', 'failure_code',
])]
class PortalNotificationPushDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(PortalNotificationRecipient::class, 'portal_notification_recipient_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BrowserPushSubscription::class, 'browser_push_subscription_id');
    }
}
