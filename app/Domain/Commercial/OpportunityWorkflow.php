<?php

namespace App\Domain\Commercial;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityActivity;
use App\Models\OpportunityStage;
use App\Models\OpportunityTask;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpportunityWorkflow
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const TASK_STATUSES = ['open', 'completed', 'canceled'];

    public const ACTIVITY_TYPES = ['note', 'call', 'email'];

    public function __construct(
        private readonly OpportunityNumber $numbers,
        private readonly CommercialDefaults $defaults,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Organization $organization, User $actor, array $attributes): Opportunity
    {
        return DB::transaction(function () use ($organization, $actor, $attributes): Opportunity {
            $stages = $this->defaults->ensure($organization);
            $stage = isset($attributes['stage_id'])
                ? $stages->firstWhere('id', (int) $attributes['stage_id'])
                : $stages->firstWhere('semantic_kind', 'new');
            abort_unless($stage, 404);
            $this->validateContext($organization, $attributes);
            $opportunity = Opportunity::query()->create([
                ...$attributes,
                'organization_id' => $organization->id,
                'opportunity_number' => $this->numbers->next($organization),
                'stage_id' => $stage->id,
                'owner_user_id' => $attributes['owner_user_id'] ?? $actor->id,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($organization, $actor, 'opportunity.created', $opportunity, [
                'stage_id' => $stage->id,
                'stage_kind' => $stage->semantic_kind,
                'changed_fields' => array_keys($attributes),
            ]);

            return $opportunity;
        });
    }

    public function update(Opportunity $opportunity, User $actor, array $attributes, bool $adminOverride = false): Opportunity
    {
        return DB::transaction(function () use ($opportunity, $actor, $attributes, $adminOverride): Opportunity {
            $opportunity = Opportunity::query()->with('stage')->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($opportunity->stage->semantic_kind === 'won') {
                throw ValidationException::withMessages(['stage_id' => 'Won Opportunities are final.']);
            }
            $this->validateContext($opportunity->organization, [...$opportunity->only(['customer_id', 'service_location_id', 'primary_contact_id', 'owner_user_id']), ...$attributes]);
            $fromStage = $opportunity->stage;
            $toStage = $fromStage;
            if (isset($attributes['stage_id'])) {
                $toStage = OpportunityStage::query()->where('organization_id', $opportunity->organization_id)->where('active', true)->findOrFail($attributes['stage_id']);
                if (in_array($toStage->semantic_kind, ['presented', 'won'], true) && ! $adminOverride) {
                    $this->audit->record($opportunity->organization, $actor, 'opportunity.stage_change_rejected', $opportunity, ['from_stage' => $fromStage->semantic_kind, 'to_stage' => $toStage->semantic_kind, 'reason_code' => 'protected_stage']);
                    throw ValidationException::withMessages(['stage_id' => 'Presented and Won require a Commercial administrator override.']);
                }
            }
            $opportunity->fill([...$attributes, 'updated_by_id' => $actor->id]);
            if ($toStage->semantic_kind === 'lost' && $fromStage->semantic_kind !== 'lost') {
                $opportunity->lost_at = now();
                $opportunity->won_at = null;
            } elseif ($fromStage->semantic_kind === 'lost' && $toStage->semantic_kind !== 'lost') {
                $opportunity->lost_at = null;
                $opportunity->lost_reason = null;
                $opportunity->lost_note = null;
            }
            if ($toStage->semantic_kind === 'won') {
                $opportunity->won_at = now();
                $opportunity->lost_at = null;
            }
            $ownerChanged = $opportunity->isDirty('owner_user_id');
            $stageChanged = (int) $fromStage->id !== (int) $toStage->id;
            $opportunity->save();
            $event = $stageChanged ? 'opportunity.stage_changed' : ($ownerChanged ? 'opportunity.owner_changed' : 'opportunity.updated');
            $this->audit->record($opportunity->organization, $actor, $event, $opportunity, [
                'changed_fields' => array_keys($attributes),
                'from_stage' => $fromStage->semantic_kind,
                'to_stage' => $toStage->semantic_kind,
                'admin_override' => $stageChanged && in_array($toStage->semantic_kind, ['presented', 'won'], true),
            ]);

            return $opportunity->refresh();
        });
    }

    public function addTask(Opportunity $opportunity, User $actor, array $attributes): OpportunityTask
    {
        return DB::transaction(function () use ($opportunity, $actor, $attributes): OpportunityTask {
            $opportunity = $this->lockMutable($opportunity);
            $this->activeMember($opportunity->organization, $attributes['assigned_to_user_id'] ?? null);
            $task = OpportunityTask::query()->create([
                ...$attributes,
                'organization_id' => $opportunity->organization_id,
                'opportunity_id' => $opportunity->id,
                'completed_at' => ($attributes['status'] ?? 'open') === 'completed' ? now() : null,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($opportunity->organization, $actor, 'opportunity_task.created', $opportunity, ['task_id' => $task->id, 'status' => $task->status, 'changed_fields' => array_keys($attributes)]);

            return $task;
        });
    }

    public function updateTask(Opportunity $opportunity, OpportunityTask $task, User $actor, array $attributes): OpportunityTask
    {
        return DB::transaction(function () use ($opportunity, $task, $actor, $attributes): OpportunityTask {
            $opportunity = $this->lockMutable($opportunity);
            $task = OpportunityTask::query()->where('organization_id', $opportunity->organization_id)->where('opportunity_id', $opportunity->id)->lockForUpdate()->findOrFail($task->id);
            $this->activeMember($opportunity->organization, $attributes['assigned_to_user_id'] ?? $task->assigned_to_user_id);
            $from = $task->status;
            $task->fill([...$attributes, 'updated_by_id' => $actor->id]);
            if ($task->status === 'completed' && $from !== 'completed') {
                $task->completed_at = now();
            }
            if ($task->status !== 'completed' && $from === 'completed') {
                $task->completed_at = null;
            }
            $task->save();
            $this->audit->record($opportunity->organization, $actor, 'opportunity_task.updated', $opportunity, ['task_id' => $task->id, 'from_status' => $from, 'to_status' => $task->status, 'changed_fields' => array_keys($attributes)]);

            return $task;
        });
    }

    public function addActivity(Opportunity $opportunity, User $actor, array $attributes): OpportunityActivity
    {
        return DB::transaction(function () use ($opportunity, $actor, $attributes): OpportunityActivity {
            $opportunity = $this->lockMutable($opportunity);
            $activity = OpportunityActivity::query()->create([
                'organization_id' => $opportunity->organization_id,
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => $attributes['type'],
                'body' => $attributes['body'] ?? null,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
            ]);
            $this->audit->record($opportunity->organization, $actor, 'opportunity_activity.created', $opportunity, ['activity_id' => $activity->id, 'activity_type' => $activity->type]);

            return $activity;
        });
    }

    private function validateContext(Organization $organization, array $attributes): void
    {
        $customer = Customer::query()->where('organization_id', $organization->id)->where('status', 'active')->findOrFail($attributes['customer_id']);
        if (filled($attributes['service_location_id'] ?? null)) {
            ServiceLocation::query()->where('organization_id', $organization->id)->where('customer_id', $customer->id)->where('active', true)->findOrFail($attributes['service_location_id']);
        }
        if (filled($attributes['primary_contact_id'] ?? null)) {
            Contact::query()->where('organization_id', $organization->id)->where('customer_id', $customer->id)->where('active', true)->findOrFail($attributes['primary_contact_id']);
        }
        $this->activeMember($organization, $attributes['owner_user_id'] ?? null);
    }

    private function activeMember(Organization $organization, mixed $userId): void
    {
        if (blank($userId)) {
            return;
        }
        abort_unless($organization->memberships()->where('user_id', (int) $userId)->where('status', 'active')->exists(), 404);
    }

    private function lockMutable(Opportunity $opportunity): Opportunity
    {
        $opportunity = Opportunity::query()->with('stage')->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
        if ($opportunity->stage->semantic_kind === 'won') {
            throw ValidationException::withMessages(['opportunity' => 'Won Opportunities are final.']);
        }

        return $opportunity;
    }
}
