<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'project_id', 'workstream_id', 'title', 'description', 'status', 'priority', 'assigned_to_user_id', 'start_on', 'due_on', 'completed_at', 'blocked_reason', 'sort_order', 'created_by_id', 'updated_by_id'])]
class ProjectTask extends Model
{
    protected function casts(): array
    {
        return ['start_on' => 'date', 'due_on' => 'date', 'completed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workstream(): BelongsTo
    {
        return $this->belongsTo(ProjectWorkstream::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
