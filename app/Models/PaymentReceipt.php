<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'invoice_id', 'payment_transaction_id', 'public_token_hash', 'token_rotated_at', 'token_rotated_by_id', 'pdf_status', 'pdf_disk', 'pdf_key', 'pdf_sha256', 'pdf_failure_code', 'generated_at'])]
class PaymentReceipt extends Model
{
    protected $hidden = ['public_token_hash', 'pdf_key'];

    protected function casts(): array
    {
        return ['token_rotated_at' => 'datetime', 'generated_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
