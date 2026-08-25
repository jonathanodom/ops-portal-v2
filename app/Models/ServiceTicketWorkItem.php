<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'service_ticket_id', 'discovered_visit_id', 'origin', 'title', 'detail', 'work_note',
    'status', 'follow_up_service_ticket_id', 'created_by_id', 'updated_by_id', 'status_changed_by_id', 'status_changed_at',
])]
class ServiceTicketWorkItem extends Model
{
    public const ORIGINS = ['field_discovered', 'office_added'];

    public const STATUSES = ['open', 'completed', 'needs_follow_up', 'transferred', 'canceled'];

    public const BLOCKING_STATUSES = ['open', 'needs_follow_up'];

    public const TERMINAL_STATUSES = ['completed', 'transferred', 'canceled'];

    protected function casts(): array
    {
        return ['status_changed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function discoveredVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'discovered_visit_id')->withTrashed();
    }

    public function followUpServiceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'follow_up_service_ticket_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_id');
    }

    public function visits(): BelongsToMany
    {
        return $this->belongsToMany(Visit::class, 'service_ticket_work_item_visit')
            ->withPivot(['organization_id', 'first_touched_by_id', 'first_touched_at', 'last_touched_by_id', 'last_touched_at'])
            ->withTimestamps();
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(VisitTimeEntry::class, 'service_ticket_work_item_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
