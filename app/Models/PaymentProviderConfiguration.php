<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'public_id', 'provider', 'environment', 'connection_method', 'api_secret', 'webhook_secret', 'location_id', 'credential_fingerprint', 'enabled', 'connection_status', 'external_account_id', 'external_account_name', 'oauth_access_token', 'oauth_refresh_token', 'oauth_expires_at', 'connected_at', 'connected_by_id', 'last_refreshed_at', 'disconnected_at', 'last_test_code', 'last_tested_at', 'last_tested_by_id', 'updated_by_id'])]
class PaymentProviderConfiguration extends Model
{
    protected $hidden = ['api_secret', 'webhook_secret', 'oauth_access_token', 'oauth_refresh_token'];

    protected function casts(): array
    {
        return [
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'oauth_access_token' => 'encrypted',
            'oauth_refresh_token' => 'encrypted',
            'enabled' => 'boolean',
            'oauth_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'disconnected_at' => 'datetime',
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
        $hasCredential = ($this->connection_method ?? 'legacy_credentials') === 'oauth'
            ? filled($this->oauth_access_token)
            : filled($this->api_secret);

        return $this->enabled && $this->connection_status === 'connected' && $hasCredential
            && ($this->provider !== 'square' || filled($this->location_id));
    }
}
