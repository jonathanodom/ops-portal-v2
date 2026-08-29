<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'project_number', 'customer_id', 'service_location_id', 'primary_contact_id', 'name', 'type', 'status', 'summary', 'objective', 'owner_user_id', 'start_on', 'target_end_on', 'completed_at', 'created_by_id', 'updated_by_id'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['start_on' => 'date', 'target_end_on' => 'date', 'completed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function serviceLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    public function workstreams(): HasMany
    {
        return $this->hasMany(ProjectWorkstream::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort_order')->orderBy('id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProjectNote::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectAttachment::class);
    }

    public function storedAttachments(): HasMany
    {
        return $this->attachments()->where('state', 'stored')->latest();
    }

    public function serviceTickets(): BelongsToMany
    {
        return $this->belongsToMany(ServiceTicket::class, 'project_service_ticket')->withPivot(['organization_id', 'project_commercial_scope_id', 'linked_by_id', 'linked_at'])->withTimestamps();
    }

    public function commercialScopes(): HasMany
    {
        return $this->hasMany(ProjectCommercialScope::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
