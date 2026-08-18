<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

final class ProjectPolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $this->hasCapability($user, $project->organization_id, 'projects.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'projects.manage');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->hasCapability($user, $project->organization_id, 'projects.manage');
    }

    public function manageTasks(User $user, Project $project): bool
    {
        return $this->hasCapability($user, $project->organization_id, 'projects.tasks.manage');
    }

    public function administer(User $user, Project $project): bool
    {
        return $this->hasCapability($user, $project->organization_id, 'projects.admin');
    }
}
