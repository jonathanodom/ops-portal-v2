<?php

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Data\TicketSummary;
use App\Domain\Projects\Support\ProjectNumber;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectNote;
use App\Models\ProjectTask;
use App\Models\ProjectWorkstream;
use App\Models\User;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectWorkflow
{
    public const TYPES = ['installation_project', 'ongoing_support', 'consulting_engineering', 'internal'];

    public const STATUSES = ['planning', 'active', 'on_hold', 'completed', 'canceled'];

    public const WORKSTREAM_STATUSES = ['planned', 'active', 'blocked', 'completed', 'canceled'];

    public const TASK_STATUSES = ['backlog', 'planned', 'in_progress', 'blocked', 'done', 'canceled'];

    public const TASK_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const MILESTONE_STATUSES = ['planned', 'in_progress', 'completed', 'canceled'];

    public const NOTE_TYPES = ['note', 'decision', 'customer_update', 'vendor_update'];

    public function __construct(
        private readonly CustomerDirectory $customers,
        private readonly ProjectNumber $numbers,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Organization $organization, User $actor, array $attributes): Project
    {
        return DB::transaction(function () use ($organization, $actor, $attributes): Project {
            $this->validateProjectContext($organization, $attributes);
            $project = Project::query()->create([
                ...$attributes,
                'organization_id' => $organization->id,
                'project_number' => $this->numbers->next($organization),
                'owner_user_id' => $attributes['owner_user_id'] ?? $actor->id,
                'completed_at' => ($attributes['status'] ?? 'planning') === 'completed' ? now() : null,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->record($project, $actor, 'project.created', ['changed_fields' => array_keys($attributes)]);

            return $project;
        });
    }

    public function update(Project $project, User $actor, array $attributes): Project
    {
        return DB::transaction(function () use ($project, $actor, $attributes): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('customer_id', $attributes) && (int) $attributes['customer_id'] !== (int) $project->customer_id && $project->serviceTickets()->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Unlink related Service Tickets before changing the Project customer.']);
            }
            $this->validateProjectContext($project->organization, [
                'customer_id' => $project->customer_id,
                'service_location_id' => $project->service_location_id,
                'primary_contact_id' => $project->primary_contact_id,
                'owner_user_id' => $attributes['owner_user_id'] ?? $project->owner_user_id,
                ...$attributes,
            ]);
            $from = $project->status;
            $project->fill([...$attributes, 'updated_by_id' => $actor->id]);
            if ($project->status === 'completed' && $from !== 'completed') {
                $project->completed_at = now();
            }
            if ($project->status !== 'completed' && $from === 'completed') {
                $project->completed_at = null;
            }
            $project->save();
            $this->record($project, $actor, $from === $project->status ? 'project.updated' : 'project.status_changed', [
                'changed_fields' => array_keys($attributes), 'from_status' => $from, 'to_status' => $project->status,
            ]);

            return $project;
        });
    }

    public function addWorkstream(Project $project, User $actor, array $attributes): ProjectWorkstream
    {
        return DB::transaction(function () use ($project, $actor, $attributes): ProjectWorkstream {
            $project = $this->lockOpen($project);
            $this->activeMember($project->organization, $attributes['owner_user_id'] ?? null);
            $record = ProjectWorkstream::query()->create([...$attributes, 'organization_id' => $project->organization_id, 'project_id' => $project->id]);
            $this->record($project, $actor, 'workstream.created', ['workstream_id' => $record->id, 'changed_fields' => array_keys($attributes)]);

            return $record;
        });
    }

    public function updateWorkstream(Project $project, ProjectWorkstream $workstream, User $actor, array $attributes): ProjectWorkstream
    {
        return DB::transaction(function () use ($project, $workstream, $actor, $attributes): ProjectWorkstream {
            $project = $this->lockOpen($project);
            $workstream = ProjectWorkstream::query()->where('project_id', $project->id)->whereKey($workstream->id)->lockForUpdate()->firstOrFail();
            $this->activeMember($project->organization, $attributes['owner_user_id'] ?? $workstream->owner_user_id);
            $from = $workstream->status;
            $workstream->update($attributes);
            $this->record($project, $actor, 'workstream.updated', ['workstream_id' => $workstream->id, 'changed_fields' => array_keys($attributes), 'from_status' => $from, 'to_status' => $workstream->status]);

            return $workstream;
        });
    }

    public function addTask(Project $project, User $actor, array $attributes): ProjectTask
    {
        return DB::transaction(function () use ($project, $actor, $attributes): ProjectTask {
            $project = $this->lockOpen($project);
            $this->validateTask($project, $attributes);
            $task = ProjectTask::query()->create([...$attributes, 'organization_id' => $project->organization_id, 'project_id' => $project->id, 'completed_at' => ($attributes['status'] ?? 'backlog') === 'done' ? now() : null, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id]);
            $this->record($project, $actor, 'task.created', ['task_id' => $task->id, 'changed_fields' => array_keys($attributes)]);

            return $task;
        });
    }

    public function updateTask(Project $project, ProjectTask $task, User $actor, array $attributes): ProjectTask
    {
        return DB::transaction(function () use ($project, $task, $actor, $attributes): ProjectTask {
            $project = $this->lockOpen($project);
            $task = ProjectTask::query()->where('project_id', $project->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->validateTask($project, [...$task->only(['workstream_id', 'status', 'assigned_to_user_id']), ...$attributes]);
            $from = $task->status;
            $task->fill([...$attributes, 'updated_by_id' => $actor->id]);
            if ($task->status === 'done' && $from !== 'done') {
                $task->completed_at = now();
            }
            if ($task->status !== 'done' && $from === 'done') {
                $task->completed_at = null;
            }
            $task->save();
            $this->record($project, $actor, 'task.status_changed', ['task_id' => $task->id, 'changed_fields' => array_keys($attributes), 'from_status' => $from, 'to_status' => $task->status]);

            return $task;
        });
    }

    public function addMilestone(Project $project, User $actor, array $attributes): ProjectMilestone
    {
        return DB::transaction(function () use ($project, $actor, $attributes): ProjectMilestone {
            $project = $this->lockOpen($project);
            $milestone = ProjectMilestone::query()->create([...$attributes, 'organization_id' => $project->organization_id, 'project_id' => $project->id, 'completed_on' => ($attributes['status'] ?? 'planned') === 'completed' ? now($project->organization->timezone)->toDateString() : null]);
            $this->record($project, $actor, 'milestone.created', ['milestone_id' => $milestone->id, 'changed_fields' => array_keys($attributes)]);

            return $milestone;
        });
    }

    public function updateMilestone(Project $project, ProjectMilestone $milestone, User $actor, array $attributes): ProjectMilestone
    {
        return DB::transaction(function () use ($project, $milestone, $actor, $attributes): ProjectMilestone {
            $project = $this->lockOpen($project);
            $milestone = ProjectMilestone::query()->where('project_id', $project->id)->whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            $from = $milestone->status;
            $milestone->fill($attributes);
            if ($milestone->status === 'completed' && $from !== 'completed') {
                $milestone->completed_on = now($project->organization->timezone)->toDateString();
            }
            if ($milestone->status !== 'completed' && $from === 'completed') {
                $milestone->completed_on = null;
            }
            $milestone->save();
            $this->record($project, $actor, 'milestone.updated', ['milestone_id' => $milestone->id, 'changed_fields' => array_keys($attributes), 'from_status' => $from, 'to_status' => $milestone->status]);

            return $milestone;
        });
    }

    public function addNote(Project $project, User $actor, array $attributes): ProjectNote
    {
        return DB::transaction(function () use ($project, $actor, $attributes): ProjectNote {
            $project = $this->lockOpen($project);
            $note = ProjectNote::query()->create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'author_id' => $actor->id, ...$attributes]);
            $this->record($project, $actor, 'project_note.created', ['note_id' => $note->id, 'note_type' => $note->type]);

            return $note;
        });
    }

    public function linkTicket(Project $project, TicketSummary $ticket, User $actor, bool $confirmLocationMismatch): void
    {
        DB::transaction(function () use ($project, $ticket, $actor, $confirmLocationMismatch): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if ($project->customer_id !== $ticket->customerId) {
                throw ValidationException::withMessages(['service_ticket_id' => 'The Service Ticket must belong to this Project customer.']);
            }
            $mismatch = $project->service_location_id !== null && $project->service_location_id !== $ticket->serviceLocationId;
            if ($mismatch && ! $confirmLocationMismatch) {
                throw ValidationException::withMessages(['confirm_location_mismatch' => 'Confirm that this Ticket belongs to a different location than the Project.']);
            }
            $project->serviceTickets()->syncWithoutDetaching([$ticket->id => ['organization_id' => $project->organization_id, 'linked_by_id' => $actor->id, 'linked_at' => now()]]);
            $this->record($project, $actor, 'service_ticket.linked', ['service_ticket_id' => $ticket->id, 'location_mismatch' => $mismatch]);
        });
    }

    public function unlinkTicket(Project $project, int $ticketId, User $actor): void
    {
        DB::transaction(function () use ($project, $ticketId, $actor): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($project->serviceTickets()->whereKey($ticketId)->exists(), 404);
            $project->serviceTickets()->detach($ticketId);
            $this->record($project, $actor, 'service_ticket.unlinked', ['service_ticket_id' => $ticketId]);
        });
    }

    public function isOverdue(ProjectTask $task, Organization $organization): bool
    {
        return $task->due_on !== null && $task->due_on->toDateString() < CarbonImmutable::now($organization->timezone)->toDateString() && ! in_array($task->status, ['done', 'canceled'], true);
    }

    private function validateProjectContext(Organization $organization, array $attributes): void
    {
        $customerId = $attributes['customer_id'] ?? null;
        if (($attributes['type'] ?? null) !== 'internal' && $customerId === null) {
            throw ValidationException::withMessages(['customer_id' => 'A Customer is required unless this is an Internal Project.']);
        }
        if ($customerId === null) {
            if (($attributes['service_location_id'] ?? null) !== null || ($attributes['primary_contact_id'] ?? null) !== null) {
                throw ValidationException::withMessages(['customer_id' => 'A Customer is required for a Location or Contact.']);
            }
        } else {
            $this->customers->resolve($organization, (int) $customerId);
            if (($attributes['service_location_id'] ?? null) !== null && ! $this->customers->locations($organization, (int) $customerId)->has((int) $attributes['service_location_id'])) {
                abort(404);
            }
            if (($attributes['primary_contact_id'] ?? null) !== null && ! $this->customers->contacts($organization, (int) $customerId)->has((int) $attributes['primary_contact_id'])) {
                abort(404);
            }
        }
        $this->activeMember($organization, $attributes['owner_user_id'] ?? null);
        if (filled($attributes['start_on'] ?? null) && filled($attributes['target_end_on'] ?? null) && $attributes['target_end_on'] < $attributes['start_on']) {
            throw ValidationException::withMessages(['target_end_on' => 'The target end date must be on or after the start date.']);
        }
    }

    private function validateTask(Project $project, array $attributes): void
    {
        if (($attributes['workstream_id'] ?? null) !== null && ! $project->workstreams()->whereKey($attributes['workstream_id'])->exists()) {
            abort(404);
        }
        $this->activeMember($project->organization, $attributes['assigned_to_user_id'] ?? null);
        if (($attributes['status'] ?? 'backlog') === 'blocked' && blank($attributes['blocked_reason'] ?? null)) {
            throw ValidationException::withMessages(['blocked_reason' => 'A blocked Task requires a reason.']);
        }
        if (filled($attributes['start_on'] ?? null) && filled($attributes['due_on'] ?? null) && $attributes['due_on'] < $attributes['start_on']) {
            throw ValidationException::withMessages(['due_on' => 'The due date must be on or after the start date.']);
        }
    }

    private function activeMember(Organization $organization, mixed $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }
        abort_unless($organization->memberships()->where('user_id', (int) $userId)->where('status', 'active')->exists(), 404);
    }

    private function lockOpen(Project $project): Project
    {
        $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        if (in_array($project->status, ['completed', 'canceled'], true)) {
            throw ValidationException::withMessages(['project' => 'Completed or canceled Projects cannot receive operational changes.']);
        }

        return $project;
    }

    private function record(Project $project, User $actor, string $event, array $metadata): void
    {
        $this->audit->record($project->organization, $actor, $event, $project, $metadata);
    }
}
