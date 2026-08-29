<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'project_id', 'name', 'description', 'status', 'target_on', 'completed_on', 'sort_order'])]
class ProjectMilestone extends Model
{
    protected function casts(): array
    {
        return ['target_on' => 'date', 'completed_on' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function billingMilestone(): HasOne
    {
        return $this->hasOne(ProjectBillingMilestone::class);
    }
}
