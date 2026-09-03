<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'organization_id', 'event_key', 'category', 'title', 'body', 'action_url',
    'related_type', 'related_id', 'actor_id', 'priority', 'metadata',
    'audience', 'default_channels', 'required_channels', 'payload_sha256', 'deduplication_hash', 'occurred_at',
])]
class PortalNotificationEvent extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'audience' => 'array',
            'default_channels' => 'array',
            'required_channels' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(PortalNotificationRecipient::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
