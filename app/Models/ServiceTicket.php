<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'customer_id', 'service_location_id', 'contact_id', 'ticket_number',
    'title', 'description', 'customer_visible_summary', 'priority', 'source', 'status', 'next_visit_number',
    'status_reason', 'status_changed_at', 'status_changed_by_id', 'created_by_id', 'updated_by_id',
])]
class ServiceTicket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status_changed_at' => 'datetime'];
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ServiceTicketNote::class);
    }

    public function reopens(): HasMany
    {
        return $this->hasMany(ServiceTicketReopen::class)->latest('reopened_at')->latest('id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function billingHandoff(): HasOne
    {
        return $this->hasOne(BillingHandoff::class);
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
