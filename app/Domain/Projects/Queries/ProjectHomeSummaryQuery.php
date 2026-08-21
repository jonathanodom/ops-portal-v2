<?php

namespace App\Domain\Projects\Queries;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use Carbon\CarbonImmutable;

final class ProjectHomeSummaryQuery
{
    public function for(Organization $organization): array
    {
        $today = CarbonImmutable::now($organization->timezone)->toDateString();
        $horizon = CarbonImmutable::parse($today, $organization->timezone)->addDays(30)->toDateString();
        $openTasks = fn ($query) => $query->whereNotIn('status', ['done', 'canceled']);

        return [
            'counts' => [
                'active' => Project::query()->forOrganization($organization->id)->where('status', 'active')->count(),
                'due_today' => ProjectTask::query()->where('organization_id', $organization->id)->whereDate('due_on', $today)->where($openTasks)->count(),
                'overdue' => ProjectTask::query()->where('organization_id', $organization->id)->whereDate('due_on', '<', $today)->where($openTasks)->count(),
                'blocked' => ProjectTask::query()->where('organization_id', $organization->id)->where('status', 'blocked')->count(),
                'upcoming_milestones' => ProjectMilestone::query()->where('organization_id', $organization->id)->whereNotIn('status', ['completed', 'canceled'])->whereBetween('target_on', [$today, $horizon])->count(),
            ],
            'overdue_tasks' => ProjectTask::query()->where('organization_id', $organization->id)
                ->whereDate('due_on', '<', $today)->where($openTasks)->with('project:id,organization_id,project_number,name')
                ->orderBy('due_on')->orderBy('id')->limit(6)->get(),
            'blocked_tasks' => ProjectTask::query()->where('organization_id', $organization->id)
                ->where('status', 'blocked')->with('project:id,organization_id,project_number,name')
                ->orderBy('updated_at')->orderBy('id')->limit(6)->get(),
            'upcoming_milestones' => ProjectMilestone::query()->where('organization_id', $organization->id)
                ->whereNotIn('status', ['completed', 'canceled'])->whereBetween('target_on', [$today, $horizon])
                ->with('project:id,organization_id,project_number,name')->orderBy('target_on')->orderBy('id')->limit(6)->get(),
        ];
    }
}
