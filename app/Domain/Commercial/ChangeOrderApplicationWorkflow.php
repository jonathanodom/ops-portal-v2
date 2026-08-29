<?php

namespace App\Domain\Commercial;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Models\CommercialRevisionLine;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\ProjectCommercialScope;
use App\Models\ProjectLaborBudgetItem;
use App\Models\ProjectMaterialPlanItem;
use App\Models\ProposalAcceptance;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeOrderApplicationWorkflow
{
    public function __construct(private readonly ProjectWorkflow $projects, private readonly AuditRecorder $audit) {}

    public function apply(ProposalAcceptance $acceptance, User $actor): ProjectCommercialScope
    {
        return DB::transaction(function () use ($acceptance, $actor): ProjectCommercialScope {
            $acceptance = ProposalAcceptance::query()->with(['publication.revision.document.project.organization', 'selections', 'milestones'])->whereKey($acceptance->id)->lockForUpdate()->firstOrFail();
            $document = $acceptance->publication->revision->document;
            if ($document->document_type !== 'change_order' || ! $document->project) {
                throw ValidationException::withMessages(['change_order' => 'This acceptance is not a Project Change Order.']);
            }
            if ($existing = ProjectCommercialScope::query()->where('proposal_acceptance_id', $acceptance->id)->first()) {
                return $existing;
            }
            $project = Project::query()->with('organization')->whereKey($document->project_id)->lockForUpdate()->firstOrFail();
            if (in_array($project->status, ['completed', 'canceled'], true)) {
                throw ValidationException::withMessages(['project' => 'An open Project is required to apply this Change Order.']);
            }
            $parent = ProjectCommercialScope::query()->where('project_id', $project->id)->orderByDesc('id')->lockForUpdate()->firstOrFail();
            if ((int) $parent->id !== (int) $document->baseline_project_commercial_scope_id) {
                throw ValidationException::withMessages(['change_order' => 'Another accepted scope was applied first. Create a new revision against the current Project total.']);
            }
            $delta = (int) $acceptance->change_order_delta_cents;
            if ($project->currentContractTotalCents() + $delta < 0) {
                throw ValidationException::withMessages(['change_order' => 'The accepted credit exceeds the current Project contract total.']);
            }
            $resulting = max(0, $project->currentContractTotalCents() + $delta);
            $scope = ProjectCommercialScope::query()->create([
                'organization_id' => $project->organization_id, 'project_id' => $project->id, 'scope_type' => 'change_order',
                'parent_scope_id' => $parent->id, 'proposal_acceptance_id' => $acceptance->id, 'commercial_revision_id' => $acceptance->commercial_revision_id,
                'accepted_snapshot_hash' => $acceptance->accepted_snapshot_hash, 'accepted_total_cents' => abs($delta),
                'contract_delta_cents' => $delta, 'resulting_contract_total_cents' => $resulting,
                'converted_by_id' => $actor->id, 'reviewed_by_id' => $actor->id, 'reviewed_at' => now(), 'converted_at' => now(),
            ]);
            $sourceLines = CommercialRevisionLine::query()->where('commercial_revision_id', $acceptance->commercial_revision_id)->with('components')->get()->keyBy('id');
            foreach ($acceptance->selections->where('included', true) as $selection) {
                $source = $sourceLines->get((int) $selection->publication_line_id);
                if (! $source) {
                    throw ValidationException::withMessages(['change_order' => 'An accepted source line is unavailable.']);
                }
                if ($source->line_type !== 'allowance') {
                    $this->planningRows($scope, $source, $selection->line_snapshot);
                }
            }
            $sign = $delta < 0 ? -1 : 1;
            foreach ($acceptance->milestones as $index => $acceptedMilestone) {
                $milestone = $this->projects->addMilestone($project, $actor, ['name' => $document->document_number.' · '.$acceptedMilestone->name, 'description' => 'Accepted Change Order billing milestone.', 'status' => 'planned', 'sort_order' => 1000 + (($index + 1) * 10)]);
                ProjectBillingMilestone::query()->create(['organization_id' => $scope->organization_id, 'project_id' => $project->id, 'project_commercial_scope_id' => $scope->id, 'project_milestone_id' => $milestone->id, 'accepted_payment_milestone_id' => $acceptedMilestone->id, 'contract_delta_cents' => $sign * (int) $acceptedMilestone->allocated_cents]);
            }
            $this->audit->record($project->organization, $actor, 'change_order.applied', $project, ['project_id' => $project->id, 'project_commercial_scope_id' => $scope->id, 'proposal_acceptance_id' => $acceptance->id, 'commercial_revision_id' => $acceptance->commercial_revision_id, 'contract_delta_cents' => $delta, 'resulting_contract_total_cents' => $resulting]);

            return $scope->fresh(['materialItems', 'laborItems', 'billingMilestones']);
        });
    }

    /** @param array<string,mixed> $snapshot */
    private function planningRows(ProjectCommercialScope $scope, CommercialRevisionLine $source, array $snapshot): void
    {
        $sign = in_array($source->change_effect, ['remove', 'substitute_remove'], true) ? -1 : 1;
        $effect = $source->change_effect;
        $dimensions = ['location_name' => $snapshot['location'] ?? null, 'system_name' => $snapshot['system'] ?? null, 'phase_name' => $snapshot['phase'] ?? null];
        if ($source->line_type === 'product') {
            $quantity = (int) $snapshot['quantity_millis'];
            ProjectMaterialPlanItem::query()->create($dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'catalog_product_id' => $source->catalog_product_id, 'source_type' => 'change_order', 'change_effect' => $effect, 'description' => $snapshot['description'], 'unit_name' => $snapshot['unit_name'], 'quantity_millis' => $quantity, 'delta_quantity_millis' => $sign * $quantity, 'cost_cents' => $source->resolved_cost_cents, 'delta_cost_cents' => $source->resolved_cost_cents === null ? null : $sign * (int) $source->resolved_cost_cents, 'sell_cents' => $snapshot['total_cents'], 'delta_sell_cents' => $sign * (int) $snapshot['total_cents']]);
        } elseif ($source->line_type === 'service') {
            $quantity = (int) $snapshot['quantity_millis'];
            ProjectLaborBudgetItem::query()->create($dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'catalog_service_id' => $source->catalog_service_id, 'source_type' => 'change_order', 'change_effect' => $effect, 'description' => $snapshot['description'], 'unit_name' => $snapshot['unit_name'], 'quantity_millis' => $quantity, 'delta_quantity_millis' => $sign * $quantity, 'cost_cents' => $source->resolved_cost_cents, 'delta_cost_cents' => $source->resolved_cost_cents === null ? null : $sign * (int) $source->resolved_cost_cents, 'sell_cents' => $snapshot['total_cents'], 'delta_sell_cents' => $sign * (int) $snapshot['total_cents']]);
        } elseif ($source->line_type === 'package') {
            foreach ($source->components as $component) {
                $quantity = $this->roundRatio((int) $source->quantity_millis * (int) $component->quantity_millis, 1000);
                $quantity = $this->roundRatio($quantity * (10000 + (int) $component->waste_basis_points), 10000);
                $base = $dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'source_component_id' => $component->id, 'source_type' => 'change_order_package', 'change_effect' => $effect, 'description' => $component->name, 'unit_name' => $component->unit_name, 'quantity_millis' => $quantity, 'delta_quantity_millis' => $sign * $quantity, 'cost_cents' => $component->cost_resolved ? $this->roundRatio($quantity * (int) $component->cost_basis_cents, max(1, (int) $component->cost_basis_quantity_millis)) : null, 'sell_cents' => $this->roundRatio($quantity * (int) $component->unit_sell_cents, 1000)];
                $base['delta_cost_cents'] = $base['cost_cents'] === null ? null : $sign * $base['cost_cents'];
                $base['delta_sell_cents'] = $sign * $base['sell_cents'];
                if ($component->component_type === 'product') {
                    ProjectMaterialPlanItem::query()->create($base + ['catalog_product_id' => $component->catalog_product_id, 'waste_basis_points' => $component->waste_basis_points]);
                } elseif ($component->component_type === 'service') {
                    ProjectLaborBudgetItem::query()->create($base + ['catalog_service_id' => $component->catalog_service_id]);
                }
            }
        }
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
