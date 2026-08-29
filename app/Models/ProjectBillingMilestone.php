<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'project_id', 'project_commercial_scope_id', 'project_milestone_id', 'accepted_payment_milestone_id'])]
class ProjectBillingMilestone extends Model
{
    public function acceptedMilestone(): BelongsTo
    {
        return $this->belongsTo(AcceptedPaymentMilestone::class, 'accepted_payment_milestone_id');
    }

    public function projectMilestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class);
    }
}
