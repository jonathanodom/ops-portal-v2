<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'invoice_id', 'payment_attempt_id', 'original_transaction_id', 'type', 'status', 'provider', 'method', 'payment_source', 'amount_cents', 'provider_transaction_id', 'safe_processor_reference', 'manual_reference', 'reason', 'idempotency_key', 'received_at', 'confirmed_at', 'recorded_by_id'])]
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

    public function displayMethodLabel(): string
    {
        return match ([$this->method, $this->payment_source]) {
            ['credit_card', 'square_pos'] => 'Credit Card · Square POS',
            ['debit_card', 'square_pos'] => 'Debit Card · Square POS',
            ['cash', 'manual'] => 'Cash',
            ['check', 'manual'] => 'Check',
            default => ucfirst(str_replace('_', ' ', (string) $this->method)),
        };
    }

    public function usesManualReversal(): bool
    {
        return $this->provider === null
            && in_array($this->payment_source, ['manual', 'square_pos'], true);
    }
}
