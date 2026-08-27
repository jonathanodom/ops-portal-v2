<?php

namespace App\Domain\Commercial;

use App\Models\CommercialPhase;
use App\Models\CommercialSystem;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationCommercialSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        });

        return OpportunityStage::query()->where('organization_id', $organization->id)->orderBy('sort_order')->orderBy('id')->get();
    }
}
