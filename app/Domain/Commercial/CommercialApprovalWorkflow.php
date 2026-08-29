<?php

namespace App\Domain\Commercial;

use App\Models\CommercialRevision;
use App\Models\CommercialRevisionApproval;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommercialApprovalWorkflow
{
    public function __construct(private readonly ApprovalPolicyEvaluator $evaluator, private readonly AuditRecorder $audit) {}

    public function submit(CommercialRevision $revision, User $actor): CommercialRevisionApproval
    {
        return DB::transaction(function () use ($revision, $actor): CommercialRevisionApproval {
            $revision = CommercialRevision::query()->with(['lines', 'document.opportunity.organization'])->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if (! $revision->isEditable()) {
                throw ValidationException::withMessages(['revision' => 'Only the current Draft may be submitted for approval.']);
            }
            $triggers = $this->evaluator->evaluate($revision);
            $status = $triggers === [] ? 'policy_pass' : 'pending';
            $approval = CommercialRevisionApproval::query()->updateOrCreate(
                ['commercial_revision_id' => $revision->id, 'content_hash' => $revision->content_hash],
                ['organization_id' => $revision->organization_id, 'status' => $status, 'trigger_snapshot' => $triggers, 'requested_by_id' => $actor->id, 'requested_at' => now(), 'decided_by_id' => $triggers === [] ? $actor->id : null, 'decided_at' => $triggers === [] ? now() : null, 'decision_reason' => null],
            );
            $revision->update(['status' => $triggers === [] ? 'approved' : 'pending_approval', 'locked_at' => now()]);
            $revision->document()->update(['status' => $revision->status, 'updated_by_id' => $actor->id]);
            $this->audit->record($revision->document->opportunity->organization, $actor, $triggers === [] ? 'quote.approval_policy_passed' : 'quote.approval_requested', $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'approval_id' => $approval->id, 'content_hash' => $revision->content_hash, 'trigger_kinds' => collect($triggers)->pluck('kind')->all()]);

            return $approval;
        });
    }

    public function decide(CommercialRevisionApproval $approval, User $actor, string $decision, string $reason): CommercialRevisionApproval
    {
        return DB::transaction(function () use ($approval, $actor, $decision, $reason): CommercialRevisionApproval {
            $approval = CommercialRevisionApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $revision = CommercialRevision::query()->with('document.opportunity.organization')->whereKey($approval->commercial_revision_id)->lockForUpdate()->firstOrFail();
            if ($approval->status !== 'pending' || $approval->content_hash !== $revision->content_hash || $revision->status !== 'pending_approval') {
                throw ValidationException::withMessages(['approval' => 'This approval request is stale or already decided.']);
            }
            $approval->update(['status' => $decision, 'decided_by_id' => $actor->id, 'decision_reason' => $reason, 'decided_at' => now()]);
            $revision->update(['status' => $decision === 'approved' ? 'approved' : 'draft', 'locked_at' => $decision === 'approved' ? now() : null]);
            $revision->document()->update(['status' => $revision->status, 'updated_by_id' => $actor->id]);
            $this->audit->record($revision->document->opportunity->organization, $actor, 'quote.approval_'.$decision, $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'approval_id' => $approval->id, 'content_hash' => $revision->content_hash, 'trigger_kinds' => collect($approval->trigger_snapshot)->pluck('kind')->all()]);

            return $approval->fresh();
        });
    }
}
