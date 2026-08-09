<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'invoice_id', 'contact_name', 'confirmed', 'presented_by_id', 'acknowledged_at', 'acknowledgment_token'])]
class InvoiceAcknowledgment extends Model
{
    protected function casts(): array
    {
        return ['confirmed' => 'boolean', 'acknowledged_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function presentedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presented_by_id');
    }
}
