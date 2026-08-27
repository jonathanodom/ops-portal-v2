<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['organization_id', 'invoice_id', 'service_ticket_id', 'schema_version', 'snapshot_json', 'snapshot_sha256', 'captured_at', 'captured_by_id'])]
class InvoiceServiceSnapshot extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice service snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Invoice service snapshots are immutable.'));
    }

    protected function casts(): array
    {
        return ['snapshot_json' => 'array', 'captured_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_id');
    }
}
