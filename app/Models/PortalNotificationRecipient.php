<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'portal_notification_event_id', 'user_id', 'channels', 'read_at',
    'email_queued_at', 'email_sent_at', 'email_failed_at',
])]
class PortalNotificationRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'read_at' => 'datetime',
            'email_queued_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'email_failed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PortalNotificationEvent::class, 'portal_notification_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pushDeliveries(): HasMany
    {
        return $this->hasMany(PortalNotificationPushDelivery::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
