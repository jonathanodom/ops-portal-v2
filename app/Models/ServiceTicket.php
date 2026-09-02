<?php

namespace App\Models;

use App\Domain\ServiceTicketPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'customer_id', 'service_location_id', 'contact_id', 'ticket_number',
    'title', 'description', 'customer_visible_summary', 'priority', 'source', 'purpose', 'billing_disposition', 'status', 'next_visit_number',
    'return_follow_up_source_ticket_id', 'return_follow_up_source_closeout_id', 'return_follow_up_original_purpose', 'return_follow_up_status',
    'status_reason', 'status_changed_at', 'status_changed_by_id', 'created_by_id', 'updated_by_id',
])]
class ServiceTicket extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status_changed_at' => 'datetime'];
    }

    public function canonicalPurpose(): string
    {
        return ServiceTicketPurpose::canonical($this->purpose);
    }

    public function purposeLabel(): string
    {
        return ServiceTicketPurpose::label($this->purpose);
    }

    public function isReturnFollowUp(): bool
    {
        return $this->return_follow_up_source_ticket_id !== null;
    }

    public function returnFollowUpSourceTicket(): BelongsTo
    {
        return $this->belongsTo(self::class, 'return_follow_up_source_ticket_id');
    }

    public function returnFollowUpTickets(): HasMany
    {
        return $this->hasMany(self::class, 'return_follow_up_source_ticket_id')->oldest('id');
    }

    public function returnFollowUpSourceCloseout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class, 'return_follow_up_source_closeout_id');
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

    public function files(): HasMany
    {
        return $this->hasMany(ServiceTicketFile::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(ServiceTicketWorkItem::class);
    }

    public function originatingWorkItem(): HasOne
    {
        return $this->hasOne(ServiceTicketWorkItem::class, 'follow_up_service_ticket_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_service_ticket')->withPivot(['organization_id', 'project_commercial_scope_id', 'linked_by_id', 'linked_at'])->withTimestamps();
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
