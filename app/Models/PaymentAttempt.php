<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'invoice_id', 'payment_provider_configuration_id', 'provider', 'amount_cents', 'status', 'idempotency_key', 'return_token_hash', 'hosted_url', 'provider_session_id', 'provider_order_id', 'provider_payment_id', 'safe_failure_code', 'initiated_by_id', 'expires_at', 'completed_at'])]
class PaymentAttempt extends Model
{
    protected $hidden = ['hosted_url', 'return_token_hash'];

    protected function casts(): array
    {
        return ['hosted_url' => 'encrypted', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderConfiguration::class, 'payment_provider_configuration_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'processing', 'unknown'], true);
    }
}
