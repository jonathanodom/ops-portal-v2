<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'document_type', 'document_number', 'opportunity_id', 'project_id', 'baseline_project_commercial_scope_id', 'title', 'status', 'created_by_id', 'updated_by_id'])]
class CommercialDocument extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function baselineScope(): BelongsTo
    {
        return $this->belongsTo(ProjectCommercialScope::class, 'baseline_project_commercial_scope_id');
    }

    public function auditSubject(): Model
    {
        return $this->document_type === 'change_order' ? $this->project : $this->opportunity;
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CommercialRevision::class)->orderByDesc('version');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
