<?php

namespace App\Domain\Commercial;

use App\Models\CommercialPhase;
use App\Models\CommercialSystem;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationCommercialSetting;
use App\Models\ProjectConversionTemplate;
use App\Models\ProposalTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CommercialDefaults
{
    private const STAGES = [
        ['New', 'new', 1000, 'blue'],
        ['Qualifying', 'qualifying', 2500, 'slate'],
        ['Quoting', 'quoting', 5000, 'orange'],
        ['Presented', 'presented', 7500, 'purple'],
        ['Won', 'won', 10000, 'green'],
        ['Lost', 'lost', 0, 'red'],
    ];

    private const SYSTEMS = ['Network', 'Surveillance', 'Audio', 'Video', 'Access Control', 'Security'];

    private const PHASES = ['Design', 'Rough-In', 'Trim', 'Final', 'Programming', 'Commissioning'];

    private const TEMPLATES = [
        ['budgetary_estimate', 'Budgetary Estimate', false],
        ['quick_quote', 'Quick Quote', true],
        ['full_project_proposal', 'Full Project Proposal', true],
        ['change_order', 'Change Order', true],
    ];

    /** @return Collection<int, OpportunityStage> */
    public function ensure(Organization $organization): Collection
    {
        DB::transaction(function () use ($organization): void {
            OrganizationCommercialSetting::query()->firstOrCreate(['organization_id' => $organization->id]);
            foreach (self::STAGES as $order => [$name, $kind, $probability, $color]) {
                OpportunityStage::query()->firstOrCreate(
                    ['organization_id' => $organization->id, 'semantic_kind' => $kind],
                    ['name' => $name, 'default_probability_bps' => $probability, 'color' => $color, 'sort_order' => $order * 10, 'active' => true],
                );
            }
            foreach (self::SYSTEMS as $order => $name) {
                CommercialSystem::query()->firstOrCreate(['organization_id' => $organization->id, 'name' => $name], ['sort_order' => ($order + 1) * 10, 'active' => true]);
            }
            foreach (self::PHASES as $order => $name) {
                CommercialPhase::query()->firstOrCreate(['organization_id' => $organization->id, 'name' => $name], ['sort_order' => ($order + 1) * 10, 'active' => true]);
            }
            if (Schema::hasTable('proposal_templates')) {
                foreach (self::TEMPLATES as [$type, $name, $acceptanceEnabled]) {
                    $template = ProposalTemplate::query()->firstOrCreate(
                        ['organization_id' => $organization->id, 'template_type' => $type],
                        ['name' => $name, 'acceptance_enabled' => $acceptanceEnabled, 'active' => true],
                    );
                    if ($template->sections()->doesntExist()) {
                        foreach ([['cover', 'Proposal'], ['scope', 'Scope of Work'], ['pricing', 'Investment'], ['terms', 'Terms']] as $index => [$sectionType, $heading]) {
                            $template->sections()->create(['section_type' => $sectionType, 'heading' => $heading, 'customer_visible' => true, 'sort_order' => ($index + 1) * 10]);
                        }
                    }
                }
            }
            if (Schema::hasTable('project_conversion_templates')) {
                $conversion = ProjectConversionTemplate::query()->firstOrCreate(
                    ['organization_id' => $organization->id, 'name' => 'Standard Project Delivery'],
                    ['active' => true],
                );
                if ($conversion->workstreams()->doesntExist()) {
                    $conversion->workstreams()->createMany([
                        ['name' => 'Planning & Coordination', 'sort_order' => 10],
                        ['name' => 'Delivery & Commissioning', 'sort_order' => 20],
                    ]);
                }
                if ($conversion->milestones()->doesntExist()) {
                    $conversion->milestones()->createMany([
                        ['name' => 'Project Start', 'billing_milestone_sort_order' => 10, 'sort_order' => 10],
                        ['name' => 'Substantial Completion', 'billing_milestone_sort_order' => 20, 'sort_order' => 20],
                    ]);
                }
            }
        });

        return OpportunityStage::query()->where('organization_id', $organization->id)->orderBy('sort_order')->orderBy('id')->get();
    }
}
