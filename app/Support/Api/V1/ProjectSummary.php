<?php

namespace App\Support\Api\V1;

use App\Models\Project;

/**
 * Shapes a Project for /api/v1, per
 * docs/OPS_PORTAL_API_IMPLEMENTATION_PLAN_CODEX_v0.1.md §8.4.
 */
final class ProjectSummary
{
    /** @return array<string, mixed> */
    public static function make(Project $project): array
    {
        return [
            'id' => (string) $project->id,
            'project_number' => $project->project_number,
            'customer_id' => $project->customer_id !== null ? (string) $project->customer_id : null,
            'location_id' => $project->service_location_id !== null ? (string) $project->service_location_id : null,
            'name' => $project->name,
            'type' => $project->type,
            'status' => $project->status,
            'summary' => $project->summary,
            'objective' => $project->objective,
            'start_on' => $project->start_on?->toDateString(),
            'target_end_on' => $project->target_end_on?->toDateString(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
