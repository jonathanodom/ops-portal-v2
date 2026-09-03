<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'title', 'body', 'audience_type', 'audience_snapshot', 'recipient_count',
    'published_by_id', 'published_at', 'publish_token_hash', 'request_sha256',
])]
final class OfficeUpdate extends Model
{
    protected function casts(): array
    {
        return [
            'audience_snapshot' => 'array',
            'recipient_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(OfficeUpdateRecipient::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
