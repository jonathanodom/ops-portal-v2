<?php

namespace App\Domain\Commercial;

use App\Models\Project;
use App\Models\ProjectAllowanceResolution;
use App\Models\ProjectCommercialScope;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectAllowanceWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function resolve(Project $project, ProjectCommercialScope $scope, int $sourceLineId, int $resolvedAmountCents, User $actor): ProjectAllowanceResolution
    {
        return DB::transaction(function () use ($project, $scope, $sourceLineId, $resolvedAmountCents, $actor): ProjectAllowanceResolution {
            $project = Project::query()->with('organization')->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $scope = ProjectCommercialScope::query()->with('acceptance.selections')->where('organization_id', $project->organization_id)->where('project_id', $project->id)->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            $selection = $scope->acceptance->selections->first(fn ($item): bool => (int) $item->publication_line_id === $sourceLineId && $item->included && ($item->line_snapshot['type'] ?? null) === 'allowance');
            if (! $selection) {
                throw ValidationException::withMessages(['allowance' => 'Choose an accepted Allowance from this Project.']);
            }
            $accepted = (int) $selection->line_snapshot['total_cents'];
            $variance = $resolvedAmountCents - $accepted;
            $existing = ProjectAllowanceResolution::query()->where('project_commercial_scope_id', $scope->id)->where('source_revision_line_id', $sourceLineId)->first();
            if ($existing) {
                if ((int) $existing->resolved_amount_cents === $resolvedAmountCents) {
                    return $existing;
                }
                throw ValidationException::withMessages(['allowance' => 'This Allowance resolution is immutable. Use a Change Order for a later variance.']);
            }
            $resolution = ProjectAllowanceResolution::query()->create([
                'organization_id' => $project->organization_id, 'project_id' => $project->id, 'project_commercial_scope_id' => $scope->id,
                'source_revision_line_id' => $sourceLineId, 'description' => $selection->line_snapshot['description'],
                'accepted_amount_cents' => $accepted, 'resolved_amount_cents' => $resolvedAmountCents, 'variance_cents' => $variance,
                'status' => $variance === 0 ? 'resolved_within_allowance' : 'change_order_required', 'resolved_by_id' => $actor->id, 'resolved_at' => now(),
            ]);
            $this->audit->record($project->organization, $actor, 'project.allowance_resolved', $project, ['project_id' => $project->id, 'project_commercial_scope_id' => $scope->id, 'allowance_resolution_id' => $resolution->id, 'source_revision_line_id' => $sourceLineId, 'variance_cents' => $variance, 'status' => $resolution->status]);

            return $resolution;
        });
    }
}
