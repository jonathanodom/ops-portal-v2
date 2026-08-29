<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'project_id', 'proposal_acceptance_id', 'commercial_revision_id', 'project_conversion_template_id', 'accepted_snapshot_hash', 'accepted_total_cents', 'converted_by_id', 'converted_at'])]
class ProjectCommercialScope extends Model
{
    protected function casts(): array
    {
        return ['converted_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function acceptance(): BelongsTo
    {
        return $this->belongsTo(ProposalAcceptance::class, 'proposal_acceptance_id');
    }

    public function materialItems(): HasMany
    {
        return $this->hasMany(ProjectMaterialPlanItem::class);
    }

    public function laborItems(): HasMany
    {
        return $this->hasMany(ProjectLaborBudgetItem::class);
    }

    public function billingMilestones(): HasMany
    {
        return $this->hasMany(ProjectBillingMilestone::class);
    }
}
