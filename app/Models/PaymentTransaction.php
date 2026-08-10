<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'invoice_id', 'payment_attempt_id', 'original_transaction_id', 'type', 'status', 'provider', 'method', 'amount_cents', 'provider_transaction_id', 'safe_processor_reference', 'manual_reference', 'reason', 'idempotency_key', 'received_at', 'confirmed_at', 'recorded_by_id'])]
class PaymentTransaction extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_transaction_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class);
    }
}
