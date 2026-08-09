<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'service_ticket_id', 'visit_id', 'closeout_id', 'status', 'approved_time_minutes', 'approved_parts_count', 'created_by_id', 'handed_off_by_id', 'handed_off_at', 'acknowledgment_token', 'current_invoice_id'])]
class BillingHandoff extends Model
{
    protected function casts(): array
    {
        return ['handed_off_at' => 'datetime'];
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function handedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_off_by_id');
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'current_invoice_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
