<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'user_id', 'endpoint', 'endpoint_sha256', 'public_key',
    'auth_token', 'content_encoding', 'user_agent', 'last_registered_at', 'disabled_at',
])]
class BrowserPushSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'last_registered_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PortalNotificationPushDelivery::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /** @return array<string, mixed> */
    public function subscriptionPayload(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => $this->public_key, 'auth' => $this->auth_token],
            'contentEncoding' => $this->content_encoding,
        ];
    }
}
