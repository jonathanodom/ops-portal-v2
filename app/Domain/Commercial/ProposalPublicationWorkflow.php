<?php

namespace App\Domain\Commercial;

use App\Jobs\RenderProposalPublicationPdf;
use App\Models\CommercialRevision;
use App\Models\OrganizationCommercialSetting;
use App\Models\ProposalPublication;
use App\Models\ProposalRecipient;
use App\Models\ProposalShareLink;
use App\Models\ProposalTemplate;
use App\Models\User;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProposalPublicationWorkflow
{
    public function __construct(private readonly ApprovalPolicyEvaluator $evaluator, private readonly CommercialOpportunityAutomation $opportunities, private readonly AuditRecorder $audit) {}

    /** @param array<string,mixed> $data */
    public function publish(CommercialRevision $revision, ProposalTemplate $template, User $actor, array $data): ProposalPublication
    {
        return DB::transaction(function () use ($revision, $template, $actor, $data): ProposalPublication {
            $revision = CommercialRevision::query()->with(['document.opportunity.organization.currentFullLogo', 'document.opportunity.customer', 'document.opportunity.serviceLocation', 'locations', 'systems', 'phases', 'sections', 'lines.components', 'lines.location', 'lines.system', 'paymentMilestones', 'media' => fn ($query) => $query->where('state', 'stored'), 'approvals'])->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $template = ProposalTemplate::query()->where('organization_id', $revision->organization_id)->where('active', true)->with('sections')->findOrFail($template->id);
            if ($revision->publication()->exists()) {
                return $revision->publication()->firstOrFail();
            }
            if ($revision->status !== 'approved') {
                throw ValidationException::withMessages(['revision' => 'Approve this exact Quote revision before publication.']);
            }
            $approval = $revision->approvals->first(fn ($item) => $item->content_hash === $revision->content_hash && in_array($item->status, ['approved', 'policy_pass'], true));
            if (! $approval || $this->evaluator->evaluate($revision) !== $approval->trigger_snapshot) {
                throw ValidationException::withMessages(['approval' => 'The approval is missing or stale for the current Quote content.']);
            }
            $acceptanceEnabled = (bool) ($data['acceptance_enabled'] ?? $template->acceptance_enabled);
            if ($acceptanceEnabled && $revision->paymentMilestones->isNotEmpty() && (! $revision->document->opportunity->service_location_id || ! $revision->document->opportunity->serviceLocation?->active)) {
                throw ValidationException::withMessages(['acceptance_enabled' => 'Choose an active Service Location before publishing an acceptance-enabled Proposal with a payment schedule.']);
            }
            if (! $revision->terms_body_snapshot) {
                throw ValidationException::withMessages(['terms' => 'Select approved terms or enter reviewed terms before publication.']);
            }
            $settings = OrganizationCommercialSetting::query()->where('organization_id', $revision->organization_id)->firstOrFail();
            $expiresAt = CarbonImmutable::parse($data['expires_at'])->utc();
            $presentation = [
                'acceptance_enabled' => $acceptanceEnabled,
                'show_line_details' => (bool) ($data['show_line_details'] ?? $settings->customer_show_line_details),
                'show_location_totals' => (bool) ($data['show_location_totals'] ?? $settings->customer_show_location_totals),
                'labor_grouping' => $data['labor_grouping'] ?? $settings->customer_labor_grouping,
                'show_manufacturer_model' => (bool) ($data['show_manufacturer_model'] ?? false),
                'show_product_images' => (bool) ($data['show_product_images'] ?? false),
                'show_package_components' => (bool) ($data['show_package_components'] ?? false),
                'expires_at' => $expiresAt->toIso8601String(),
                'brand_asset_id' => $revision->document->opportunity->organization->full_logo_asset_id,
            ];
            $snapshot = $this->snapshot($revision, $template);
            $snapshot['publication'] = $presentation;
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $publication = ProposalPublication::query()->create([
                'organization_id' => $revision->organization_id, 'commercial_revision_id' => $revision->id, 'proposal_template_id' => $template->id,
                'revision_content_hash' => $revision->content_hash, 'publication_hash' => $hash, 'snapshot' => $snapshot,
                'subtotal_cents' => $revision->subtotal_cents, 'discount_cents' => $revision->line_discount_total_cents + $revision->quote_discount_total_cents,
                'tax_cents' => $revision->tax_total_cents, 'total_cents' => $revision->total_cents, 'acceptance_enabled' => $acceptanceEnabled,
                'show_line_details' => $presentation['show_line_details'],
                'show_location_totals' => $presentation['show_location_totals'],
                'labor_grouping' => $presentation['labor_grouping'],
                'show_manufacturer_model' => $presentation['show_manufacturer_model'], 'show_product_images' => $presentation['show_product_images'],
                'show_package_components' => $presentation['show_package_components'], 'brand_asset_id' => $presentation['brand_asset_id'],
                'expires_at' => $expiresAt, 'published_by_id' => $actor->id, 'published_at' => now(),
            ]);
            $revision->update(['status' => 'published', 'locked_at' => now()]);
            $revision->document()->update(['status' => 'published', 'updated_by_id' => $actor->id]);
            ProposalPublication::query()
                ->where('id', '!=', $publication->id)
                ->where('status', 'changes_requested')
                ->whereHas('revision', fn ($query) => $query->where('commercial_document_id', $revision->commercial_document_id))
                ->update(['status' => 'superseded', 'superseded_at' => now()]);
            $this->audit->record($revision->document->opportunity->organization, $actor, 'proposal.publication_created', $revision->document->opportunity, ['quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'publication_id' => $publication->id, 'template_id' => $template->id, 'publication_hash' => $hash, 'total_cents' => $revision->total_cents]);
            RenderProposalPublicationPdf::dispatch($publication->id)->afterCommit();

            return $publication;
        });
    }

    /** @return array{0:ProposalRecipient,1:string} */
    public function addRecipient(ProposalPublication $publication, User $actor, string $email, ?string $name): array
    {
        return DB::transaction(function () use ($publication, $actor, $email, $name): array {
            $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($publication->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $token = Str::random(80);
            $recipient = $publication->recipients()->create(['organization_id' => $publication->organization_id, 'name' => $name, 'email' => $email, 'token_hash' => hash('sha256', $token), 'created_by_id' => $actor->id]);
            $this->audit->record($publication->revision->document->opportunity->organization, $actor, 'proposal.recipient_created', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'recipient_id' => $recipient->id]);

            return [$recipient, $token];
        });
    }

    /** @return array{0:ProposalShareLink,1:string} */
    public function addShareLink(ProposalPublication $publication, User $actor, ?string $label): array
    {
        return DB::transaction(function () use ($publication, $actor, $label): array {
            $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($publication->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $token = Str::random(80);
            $link = $publication->shareLinks()->create(['organization_id' => $publication->organization_id, 'label' => $label, 'token_hash' => hash('sha256', $token), 'created_by_id' => $actor->id]);
            $this->opportunities->presented($publication->revision->document->opportunity, $actor, $publication->id);
            $this->audit->record($publication->revision->document->opportunity->organization, $actor, 'proposal.share_link_created', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'share_link_id' => $link->id]);

            return [$link, $token];
        });
    }

    public function withdraw(ProposalPublication $publication, User $actor): ProposalPublication
    {
        return DB::transaction(function () use ($publication, $actor): ProposalPublication {
            $publication = ProposalPublication::query()->with('revision.document.opportunity.organization')->whereKey($publication->id)->lockForUpdate()->firstOrFail();
            if ($publication->status !== 'active') {
                throw ValidationException::withMessages(['publication' => 'Only an active publication may be withdrawn.']);
            }
            $publication->update(['status' => 'withdrawn', 'withdrawn_by_id' => $actor->id, 'withdrawn_at' => now()]);
            $this->audit->record($publication->revision->document->opportunity->organization, $actor, 'proposal.publication_withdrawn', $publication->revision->document->opportunity, ['publication_id' => $publication->id, 'revision_id' => $publication->commercial_revision_id]);

            return $publication->fresh();
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(CommercialRevision $revision, ProposalTemplate $template): array
    {
        $opportunity = $revision->document->opportunity;
        $organization = $opportunity->organization;

        return [
            'schema_version' => 1,
            'document' => ['number' => $revision->displayNumber(), 'title' => $revision->document->title, 'currency' => $revision->currency],
            'seller' => $organization->only(['name', 'legal_name', 'email', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code']),
            'customer' => ['display_name' => $opportunity->customer->display_name, 'legal_name' => $opportunity->customer->legal_name, 'site_name' => $opportunity->serviceLocation?->name],
            'template' => ['id' => $template->id, 'type' => $template->template_type, 'name' => $template->name, 'sections' => $template->sections->map->only(['section_type', 'heading', 'customer_visible', 'sort_order'])->all()],
            'sections' => $revision->sections->where('customer_visible', true)->map->only(['id', 'heading', 'body', 'sort_order'])->values()->all(),
            'terms' => ['name' => $revision->terms_name_snapshot, 'version' => $revision->terms_version_snapshot, 'body' => $revision->terms_body_snapshot],
            'lines' => $revision->lines->map(fn ($line) => ['id' => $line->id, 'type' => $line->line_type, 'catalog_product_id' => $line->catalog_product_id, 'catalog_service_id' => $line->catalog_service_id, 'catalog_service_variant_id' => $line->catalog_service_variant_id, 'catalog_package_id' => $line->catalog_package_id, 'catalog_unit_sell_cents' => $line->catalog_unit_sell_cents, 'effective_unit_sell_cents' => $line->effective_unit_sell_cents, 'discount_type' => $line->discount_type, 'discount_value' => $line->discount_value, 'description' => $line->description, 'customer_description' => $line->customer_description, 'quantity_millis' => $line->quantity_millis, 'unit_name' => $line->unit_name, 'optional' => $line->optional, 'included' => $line->included, 'taxable' => $line->taxable, 'gross_sell_cents' => $line->gross_sell_cents, 'discount_cents' => $line->line_discount_cents + $line->quote_discount_cents, 'tax_cents' => $line->tax_cents, 'total_cents' => $line->total_cents, 'location' => $line->location?->name, 'system' => $line->system?->name, 'components' => $line->components->where('customer_visible', true)->map->only(['name', 'quantity_millis', 'unit_name'])->values()->all()])->all(),
            'pricing' => ['discount_type' => $revision->discount_type, 'discount_value' => $revision->discount_value, 'tax_rate_basis_points' => $revision->tax_rate_basis_points, 'customer_tax_exempt' => $revision->customer_tax_exempt],
            'milestones' => $revision->paymentMilestones->map->only(['id', 'name', 'amount_type', 'amount_value', 'allocated_cents', 'is_balancing', 'sort_order'])->all(),
            'media' => $revision->media->map->only(['id', 'media_type', 'original_name', 'mime_type', 'byte_size', 'sha256', 'embed_url', 'caption'])->all(),
            'totals' => ['subtotal_cents' => $revision->subtotal_cents, 'line_discount_cents' => $revision->line_discount_total_cents, 'quote_discount_cents' => $revision->quote_discount_total_cents, 'tax_cents' => $revision->tax_total_cents, 'total_cents' => $revision->total_cents],
            'acceptance' => ['version' => config('commercial.acceptance_statement_version'), 'statement' => config('commercial.acceptance_statement')],
        ];
    }
}
