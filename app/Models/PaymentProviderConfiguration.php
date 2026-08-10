<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'public_id', 'provider', 'environment', 'api_secret', 'webhook_secret', 'location_id', 'credential_fingerprint', 'enabled', 'connection_status', 'external_account_id', 'last_test_code', 'last_tested_at', 'last_tested_by_id', 'updated_by_id'])]
class PaymentProviderConfiguration extends Model
{
    protected $hidden = ['api_secret', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->connection_status === 'connected' && filled($this->api_secret)
            && ($this->provider !== 'square' || filled($this->location_id));
    }
}
