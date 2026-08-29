<?php

namespace App\Domain\Commercial;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\ServiceTicketCreator;
use App\Models\CommercialRevisionLine;
use App\Models\Project;
use App\Models\ProjectBillingMilestone;
use App\Models\ProjectCommercialScope;
use App\Models\ProjectConversionTemplate;
use App\Models\ProjectLaborBudgetItem;
use App\Models\ProjectMaterialPlanItem;
use App\Models\ProposalAcceptance;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectConversionWorkflow
{
    public function __construct(
        private readonly ProjectWorkflow $projects,
        private readonly ServiceTicketCreator $tickets,
        private readonly ServiceOperationsDirectory $operations,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function convert(ProposalAcceptance $acceptance, User $actor, array $data): ProjectCommercialScope
    {
        return DB::transaction(function () use ($acceptance, $actor, $data): ProjectCommercialScope {
            $acceptance = ProposalAcceptance::query()
                ->with(['publication.revision.document.opportunity.organization', 'selections', 'milestones'])
                ->lockForUpdate()->findOrFail($acceptance->id);
            if ($existing = ProjectCommercialScope::query()->where('proposal_acceptance_id', $acceptance->id)->first()) {
                return $existing;
            }
            $opportunity = $acceptance->publication->revision->document->opportunity;
            $template = filled($data['project_conversion_template_id'] ?? null)
                ? ProjectConversionTemplate::query()->where('organization_id', $acceptance->organization_id)->where('active', true)->with(['workstreams', 'milestones'])->findOrFail((int) $data['project_conversion_template_id'])
                : null;
            $project = $this->resolveProject($acceptance, $actor, $data);
            if ((int) $project->customer_id !== (int) $opportunity->customer_id) {
                throw ValidationException::withMessages(['project_id' => 'The Project must belong to the accepted Proposal customer.']);
            }
            $differentLocation = $project->service_location_id && (int) $project->service_location_id !== (int) $opportunity->service_location_id;
            if ($differentLocation && ! ($data['confirm_location_mismatch'] ?? false)) {
                throw ValidationException::withMessages(['confirm_location_mismatch' => 'Confirm the accepted Proposal uses a different location than this Project.']);
            }
            $scope = ProjectCommercialScope::query()->create([
                'organization_id' => $acceptance->organization_id,
                'project_id' => $project->id,
                'proposal_acceptance_id' => $acceptance->id,
                'commercial_revision_id' => $acceptance->commercial_revision_id,
                'project_conversion_template_id' => $template?->id,
                'accepted_snapshot_hash' => $acceptance->accepted_snapshot_hash,
                'accepted_total_cents' => $acceptance->total_cents,
                'scope_type' => 'baseline',
                'contract_delta_cents' => 0,
                'resulting_contract_total_cents' => $acceptance->total_cents,
                'converted_by_id' => $actor->id,
                'converted_at' => now(),
            ]);

            foreach ($template?->workstreams ?? [] as $definition) {
                $this->projects->addWorkstream($project, $actor, ['name' => $definition->name, 'description' => $definition->description, 'status' => 'planned', 'owner_user_id' => $project->owner_user_id, 'sort_order' => $definition->sort_order]);
            }
            $projectMilestones = collect();
            $billingMap = collect();
            foreach ($template?->milestones ?? [] as $definition) {
                $created = $this->projects->addMilestone($project, $actor, ['name' => $definition->name, 'description' => $definition->description, 'status' => 'planned', 'sort_order' => $definition->sort_order]);
                $projectMilestones->push($created);
                if ($definition->billing_milestone_sort_order !== null) {
                    $billingMap->put((int) $definition->billing_milestone_sort_order, $created);
                }
            }
            foreach ($acceptance->milestones as $index => $acceptedMilestone) {
                $projectMilestone = $billingMap->get((int) $acceptedMilestone->sort_order) ?? $projectMilestones->get($index) ?? $this->projects->addMilestone($project, $actor, ['name' => $acceptedMilestone->name, 'description' => 'Accepted commercial billing milestone.', 'status' => 'planned', 'sort_order' => ($index + 1) * 10]);
                ProjectBillingMilestone::query()->create(['organization_id' => $scope->organization_id, 'project_id' => $project->id, 'project_commercial_scope_id' => $scope->id, 'project_milestone_id' => $projectMilestone->id, 'accepted_payment_milestone_id' => $acceptedMilestone->id]);
            }

            $sourceLines = CommercialRevisionLine::query()->where('commercial_revision_id', $acceptance->commercial_revision_id)->with('components')->get()->keyBy('id');
            foreach ($acceptance->selections->where('included', true) as $selection) {
                $snapshot = $selection->line_snapshot;
                if (($snapshot['type'] ?? null) === 'allowance') {
                    continue;
                }
                $source = $sourceLines->get((int) $selection->publication_line_id);
                if (! $source) {
                    throw ValidationException::withMessages(['proposal' => 'An accepted source line is unavailable for conversion.']);
                }
                $this->createPlanningRows($scope, $source, $snapshot);
            }
            $this->createSelectedTickets($scope, $project, $opportunity->service_location_id, $acceptance, $actor, $data['ticket_line_ids'] ?? []);
            $this->audit->record($project->organization, $actor, 'project.commercial_scope_converted', $project, [
                'project_id' => $project->id, 'project_commercial_scope_id' => $scope->id,
                'proposal_acceptance_id' => $acceptance->id, 'commercial_revision_id' => $acceptance->commercial_revision_id,
                'material_item_ids' => $scope->materialItems()->pluck('id')->all(), 'labor_item_ids' => $scope->laborItems()->pluck('id')->all(),
            ]);

            return $scope->fresh(['project', 'materialItems', 'laborItems', 'billingMilestones']);
        });
    }

    /** @param array<string,mixed> $data */
    private function resolveProject(ProposalAcceptance $acceptance, User $actor, array $data): Project
    {
        $opportunity = $acceptance->publication->revision->document->opportunity;
        if (($data['project_mode'] ?? null) === 'existing') {
            $project = Project::query()->where('organization_id', $acceptance->organization_id)->lockForUpdate()->findOrFail((int) ($data['project_id'] ?? 0));
            if (in_array($project->status, ['completed', 'canceled'], true)) {
                throw ValidationException::withMessages(['project_id' => 'Choose an open Project.']);
            }

            return $project;
        }

        return $this->projects->create($opportunity->organization, $actor, [
            'customer_id' => $opportunity->customer_id,
            'service_location_id' => $opportunity->service_location_id,
            'primary_contact_id' => $opportunity->primary_contact_id,
            'name' => $data['project_name'],
            'type' => $data['project_type'],
            'status' => 'planning',
            'summary' => 'Created from accepted Proposal '.$acceptance->publication->snapshot['document']['number'].'.',
            'objective' => null,
            'owner_user_id' => $actor->id,
            'start_on' => null,
            'target_end_on' => null,
        ]);
    }

    /** @param array<string,mixed> $snapshot */
    private function createPlanningRows(ProjectCommercialScope $scope, CommercialRevisionLine $source, array $snapshot): void
    {
        $dimensions = ['location_name' => $snapshot['location'] ?? null, 'system_name' => $snapshot['system'] ?? null, 'phase_name' => $snapshot['phase'] ?? null];
        if ($source->line_type === 'product') {
            ProjectMaterialPlanItem::query()->create($dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'source_component_id' => null, 'catalog_product_id' => $source->catalog_product_id, 'source_type' => 'direct', 'description' => $snapshot['description'], 'unit_name' => $snapshot['unit_name'], 'quantity_millis' => $snapshot['quantity_millis'], 'cost_cents' => $source->resolved_cost_cents, 'sell_cents' => $snapshot['total_cents']]);
        } elseif ($source->line_type === 'service') {
            ProjectLaborBudgetItem::query()->create($dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'source_component_id' => null, 'catalog_service_id' => $source->catalog_service_id, 'source_type' => 'direct', 'description' => $snapshot['description'], 'unit_name' => $snapshot['unit_name'], 'quantity_millis' => $snapshot['quantity_millis'], 'cost_cents' => $source->resolved_cost_cents, 'sell_cents' => $snapshot['total_cents']]);
        } elseif ($source->line_type === 'package') {
            foreach ($source->components as $component) {
                $quantity = $this->roundRatio((int) $source->quantity_millis * (int) $component->quantity_millis, 1000);
                $quantity = $this->roundRatio($quantity * (10000 + (int) $component->waste_basis_points), 10000);
                $base = $dimensions + ['organization_id' => $scope->organization_id, 'project_id' => $scope->project_id, 'project_commercial_scope_id' => $scope->id, 'source_revision_line_id' => $source->id, 'source_component_id' => $component->id, 'source_type' => 'package', 'description' => $component->name, 'unit_name' => $component->unit_name, 'quantity_millis' => $quantity, 'cost_cents' => $component->cost_resolved ? $this->roundRatio($quantity * (int) $component->cost_basis_cents, max(1, (int) $component->cost_basis_quantity_millis)) : null, 'sell_cents' => $this->roundRatio($quantity * (int) $component->unit_sell_cents, 1000)];
                if ($component->component_type === 'product') {
                    ProjectMaterialPlanItem::query()->create($base + ['catalog_product_id' => $component->catalog_product_id, 'waste_basis_points' => $component->waste_basis_points]);
                } elseif ($component->component_type === 'service') {
                    ProjectLaborBudgetItem::query()->create($base + ['catalog_service_id' => $component->catalog_service_id]);
                }
            }
        }
    }

    /** @param array<int|string> $lineIds */
    private function createSelectedTickets(ProjectCommercialScope $scope, Project $project, ?int $locationId, ProposalAcceptance $acceptance, User $actor, array $lineIds): void
    {
        $selected = collect($lineIds)->map(fn ($id) => (int) $id)->unique();
        if ($selected->isNotEmpty() && ! $locationId) {
            throw ValidationException::withMessages(['ticket_line_ids' => 'An active Service Location is required to create selected Service Tickets.']);
        }
        $lines = $acceptance->selections->where('included', true)->keyBy('publication_line_id');
        foreach ($selected as $lineId) {
            $selection = $lines->get($lineId);
            if (! $selection) {
                throw ValidationException::withMessages(['ticket_line_ids' => 'Only accepted scope lines may create Service Tickets.']);
            }
            $snapshot = $selection->line_snapshot;
            $ticket = $this->tickets->create($project->organization, $actor, ['customer_id' => $project->customer_id, 'service_location_id' => $locationId, 'contact_id' => $project->primary_contact_id, 'title' => $snapshot['description'], 'description' => $snapshot['customer_description'] ?? $snapshot['description'], 'customer_visible_summary' => $snapshot['customer_description'] ?? null, 'priority' => 'normal', 'source' => 'internal', 'purpose' => 'installation_project', 'billing_disposition' => 'included'], false);
            $this->projects->linkTicket($project, $this->operations->resolve($project->organization, $ticket->id), $actor, true);
            DB::table('project_service_ticket')->where('project_id', $project->id)->where('service_ticket_id', $ticket->id)->update(['project_commercial_scope_id' => $scope->id]);
        }
    }

    private function roundRatio(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
