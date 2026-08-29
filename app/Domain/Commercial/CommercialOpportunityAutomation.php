<?php

namespace App\Domain\Commercial;

use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\User;
use App\Support\AuditRecorder;

final class CommercialOpportunityAutomation
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function presented(Opportunity $opportunity, ?User $actor, int $publicationId): void
    {
        $opportunity->loadMissing(['stage', 'organization']);
        if (in_array($opportunity->stage->semantic_kind, ['won', 'lost', 'presented'], true)) {
            return;
        }
        $this->move($opportunity, 'presented', $actor, 'proposal_first_shared', $publicationId);
    }

    public function quoting(Opportunity $opportunity, int $publicationId): void
    {
        $opportunity->loadMissing(['stage', 'organization']);
        if ($opportunity->stage->semantic_kind === 'won') {
            return;
        }
        $this->move($opportunity, 'quoting', null, 'proposal_changes_requested', $publicationId);
    }

    public function won(Opportunity $opportunity, int $publicationId): void
    {
        $opportunity->loadMissing(['stage', 'organization']);
        if ($opportunity->stage->semantic_kind === 'won') {
            return;
        }
        $this->move($opportunity, 'won', null, 'proposal_accepted', $publicationId);
        $opportunity->forceFill(['won_at' => now(), 'lost_at' => null])->save();
    }

    private function move(Opportunity $opportunity, string $kind, ?User $actor, string $reason, int $publicationId): void
    {
        $stage = OpportunityStage::query()->where('organization_id', $opportunity->organization_id)->where('semantic_kind', $kind)->where('active', true)->firstOrFail();
        $from = $opportunity->stage->semantic_kind;
        $attributes = ['stage_id' => $stage->id];
        if ($actor) {
            $attributes['updated_by_id'] = $actor->id;
        }
        $opportunity->forceFill($attributes)->save();
        $this->audit->record($opportunity->organization, $actor, 'opportunity.stage_changed', $opportunity, ['from_stage' => $from, 'to_stage' => $kind, 'automation' => $reason, 'publication_id' => $publicationId]);
    }
}
