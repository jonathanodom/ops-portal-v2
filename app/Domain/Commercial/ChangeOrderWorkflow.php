<?php

namespace App\Domain\Commercial;

use App\Models\CommercialDocument;
use App\Models\CommercialPhase;
use App\Models\CommercialRevision;
use App\Models\CommercialSystem;
use App\Models\OrganizationBillingSetting;
use App\Models\Project;
use App\Models\ProjectCommercialScope;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeOrderWorkflow
{
    public function __construct(private readonly ChangeOrderNumber $numbers, private readonly QuoteWorkflow $quotes, private readonly AuditRecorder $audit) {}

    public function create(Project $project, User $actor, string $title): CommercialDocument
    {
        return DB::transaction(function () use ($project, $actor, $title): CommercialDocument {
            $project = Project::query()->with(['organization', 'serviceLocation'])->whereKey($project->id)->lockForUpdate()->firstOrFail();
            if (in_array($project->status, ['completed', 'canceled'], true)) {
                throw ValidationException::withMessages(['project' => 'Change Orders require an open Project.']);
            }
            $baseline = ProjectCommercialScope::query()->where('project_id', $project->id)->orderByDesc('id')->lockForUpdate()->first();
            if (! $baseline) {
                throw ValidationException::withMessages(['project' => 'Convert an accepted Proposal before creating a Change Order.']);
            }
            $baselineAcceptance = $baseline->acceptance()->with('publication.revision.document.opportunity.customer')->firstOrFail();
            $opportunity = $baselineAcceptance->publication->revision->document->opportunity;
            $opportunityId = $opportunity->id;
            $document = CommercialDocument::query()->create([
                'organization_id' => $project->organization_id, 'document_type' => 'change_order',
                'document_number' => $this->numbers->next($project->organization), 'opportunity_id' => $opportunityId,
                'project_id' => $project->id, 'baseline_project_commercial_scope_id' => $baseline->id,
                'title' => $title, 'status' => 'draft', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $taxRate = (int) (OrganizationBillingSetting::query()->where('organization_id', $project->organization_id)->value('default_tax_rate_basis_points') ?? 0);
            $revision = CommercialRevision::query()->create([
                'organization_id' => $project->organization_id, 'commercial_document_id' => $document->id,
                'version' => 1, 'status' => 'draft', 'currency' => 'USD', 'tax_rate_basis_points' => $taxRate,
                'customer_tax_exempt' => (bool) $opportunity->customer->tax_exempt, 'tax_exemption_reference' => $opportunity->customer->tax_exemption_reference,
                'resulting_project_total_cents' => $project->currentContractTotalCents(), 'content_hash' => str_repeat('0', 64),
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $revision->locations()->create(['organization_id' => $project->organization_id, 'name' => $project->serviceLocation?->name ?? 'Project scope', 'sort_order' => 10]);
            foreach (CommercialSystem::query()->where('organization_id', $project->organization_id)->where('active', true)->orderBy('sort_order')->get() as $system) {
                $revision->systems()->create(['organization_id' => $project->organization_id, 'source_default_id' => $system->id, 'name' => $system->name, 'sort_order' => $system->sort_order]);
            }
            foreach (CommercialPhase::query()->where('organization_id', $project->organization_id)->where('active', true)->orderBy('sort_order')->get() as $phase) {
                $revision->phases()->create(['organization_id' => $project->organization_id, 'source_default_id' => $phase->id, 'name' => $phase->name, 'sort_order' => $phase->sort_order]);
            }
            $this->quotes->refresh($revision, $actor, false);
            $this->audit->record($project->organization, $actor, 'change_order.created', $project, ['project_id' => $project->id, 'commercial_document_id' => $document->id, 'revision_id' => $revision->id, 'document_number' => $document->document_number]);

            return $document->fresh('revisions');
        });
    }
}
