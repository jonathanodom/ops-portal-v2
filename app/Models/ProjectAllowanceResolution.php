<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'project_id', 'project_commercial_scope_id', 'source_revision_line_id', 'description', 'accepted_amount_cents', 'resolved_amount_cents', 'variance_cents', 'status', 'resolved_by_id', 'resolved_at'])]
class ProjectAllowanceResolution extends Model
{
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }
}
