<?php

namespace App\Domain\Commercial;

use App\Jobs\NotifyProposalOwner;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Opportunity;
use App\Models\ProposalComment;
use App\Models\ProposalPublication;
use App\Models\User;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProposalResponseWorkflow
{
    public function __construct(
        private readonly QuoteWorkflow $quotes,
        private readonly ProposalEngagementWorkflow $engagement,
        private readonly CommercialOpportunityAutomation $opportunities,
        private readonly AuditRecorder $audit,
    ) {}

    public function requestChanges(ProposalAccess $access, Request $request, array $data): int
    {
        $this->engagement->assertActionable($access);

        return DB::transaction(function () use ($access, $request, $data): int {
            $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($access->publication->id)->lockForUpdate()->firstOrFail();
            if ($publication->status !== 'active' || $publication->acceptance()->exists()) {
                throw ValidationException::withMessages(['proposal' => 'This Proposal is no longer available for change requests.']);
            }
            $comment = ProposalComment::query()->create([
                'organization_id' => $publication->organization_id, 'proposal_publication_id' => $publication->id,
                'proposal_recipient_id' => $access->recipientId(), 'proposal_share_link_id' => $access->shareLinkId(),
                'author_type' => 'customer', 'author_name' => $data['name'], 'author_email' => $data['email'] ?? null,
                'target_type' => 'proposal', 'body' => $data['body'],
            ]);
            $draft = $this->quotes->cloneDraft($publication->revision, null);
            $publication->update(['status' => 'changes_requested', 'changes_requested_at' => now()]);
            $acceptedExists = ProposalPublication::query()->whereHas('revision.document', fn ($query) => $query->where('opportunity_id', $publication->revision->document->opportunity_id))->where('status', 'accepted')->exists();
            if ($publication->revision->document->document_type === 'quote' && ! $acceptedExists) {
                $opportunity = Opportunity::query()->whereKey($publication->revision->document->opportunity_id)->lockForUpdate()->firstOrFail();
                $this->opportunities->quoting($opportunity, $publication->id);
            }
            $event = $this->engagement->event($access, 'changes_requested', $request, 'proposal', null, ['comment_id' => $comment->id, 'new_revision_id' => $draft->id]);
            $this->audit->record($publication->revision->document->opportunity->organization, null, 'proposal.changes_requested', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'source_revision_id' => $publication->commercial_revision_id, 'new_revision_id' => $draft->id, 'comment_id' => $comment->id]);
            NotifyProposalOwner::dispatch($event->id)->afterCommit();

            return $draft->id;
        });
    }

    public function extend(ProposalPublication $publication, User $actor, string $expiresOn, string $organizationTimezone): ProposalPublication
    {
        return DB::transaction(function () use ($publication, $actor, $expiresOn, $organizationTimezone): ProposalPublication {
            $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if (! in_array($publication->status, ['active', 'expired'], true) || $publication->acceptance()->exists()) {
                throw ValidationException::withMessages(['publication' => 'Only an unaccepted active or expired Proposal may be extended.']);
            }
            $differences = $this->catalogDifferences($publication);
            if ($differences !== []) {
                throw ValidationException::withMessages(['publication' => 'Current Catalog pricing differs from this publication. Create a new Draft revision instead of extending it.']);
            }
            $expiresAt = CarbonImmutable::parse($expiresOn, $organizationTimezone)->endOfDay()->utc();
            if ($expiresAt->isPast()) {
                throw ValidationException::withMessages(['expires_on' => 'Choose a future expiration date.']);
            }
            $review = ['reviewed_line_ids' => collect($publication->snapshot['lines'])->pluck('id')->map(fn ($id) => (int) $id)->all(), 'differences' => [], 'reviewed_at' => now()->toIso8601String()];
            $publication->update(['status' => 'active', 'expires_at' => $expiresAt, 'extended_at' => now(), 'extended_by_id' => $actor->id, 'extension_review_snapshot' => $review]);
            $this->audit->record($publication->revision->document->opportunity->organization, $actor, 'proposal.expiration_extended', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'revision_id' => $publication->commercial_revision_id, 'reviewed_line_ids' => $review['reviewed_line_ids'], 'catalog_differences' => 0]);

            return $publication->fresh();
        });
    }

    /** @return array<int,int> */
    private function catalogDifferences(ProposalPublication $publication): array
    {
        $differences = [];
        foreach ($publication->snapshot['lines'] as $line) {
            $current = match ($line['type']) {
                'product' => CatalogProduct::query()->where('organization_id', $publication->organization_id)->find($line['catalog_product_id'] ?? null)?->default_sell_price_cents,
                'service' => isset($line['catalog_service_variant_id']) && $line['catalog_service_variant_id']
                    ? (CatalogServiceVariant::query()->where('organization_id', $publication->organization_id)->find($line['catalog_service_variant_id'])?->price_override_cents
                        ?? CatalogService::query()->where('organization_id', $publication->organization_id)->find($line['catalog_service_id'] ?? null)?->default_price_cents)
                    : CatalogService::query()->where('organization_id', $publication->organization_id)->find($line['catalog_service_id'] ?? null)?->default_price_cents,
                'package' => CatalogPackage::query()->where('organization_id', $publication->organization_id)->find($line['catalog_package_id'] ?? null)?->default_price_cents,
                default => $line['catalog_unit_sell_cents'] ?? null,
            };
            if ($current !== null && (int) $current !== (int) ($line['catalog_unit_sell_cents'] ?? $line['effective_unit_sell_cents'])) {
                $differences[] = (int) $line['id'];
            }
        }

        return $differences;
    }
}
