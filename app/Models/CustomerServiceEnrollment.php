<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'customer_id', 'service_location_id', 'catalog_service_id',
    'catalog_service_variant_id', 'status', 'start_date', 'end_date',
    'next_billing_date', 'billing_amount_cents', 'billing_amount_override_reason', 'billing_cadence', 'billing_interval',
    'taxable_snapshot', 'service_code_snapshot', 'service_name_snapshot',
    'service_description_snapshot', 'service_unit_code_snapshot',
    'service_unit_name_snapshot', 'variant_code_snapshot', 'variant_label_snapshot',
    'internal_notes', 'current_scope_key', 'status_changed_at', 'status_changed_by_id',
    'canceled_at', 'canceled_by_id', 'created_by_id', 'updated_by_id',
])]
class CustomerServiceEnrollment extends Model
{
    public const STATUSES = ['active', 'paused', 'canceled'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_billing_date' => 'date',
            'taxable_snapshot' => 'boolean',
            'status_changed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    public function catalogService(): BelongsTo
    {
        return $this->belongsTo(CatalogService::class);
    }

    public function catalogServiceVariant(): BelongsTo
    {
        return $this->belongsTo(CatalogServiceVariant::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, ['active', 'paused'], true);
    }
}
