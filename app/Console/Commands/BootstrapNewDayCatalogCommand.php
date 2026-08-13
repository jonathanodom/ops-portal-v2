<?php

namespace App\Console\Commands;

use App\Domain\NewDayCatalogBootstrap;
use App\Models\Organization;
use Illuminate\Console\Command;

class BootstrapNewDayCatalogCommand extends Command
{
    protected $signature = 'catalog:bootstrap-newday {--organization= : Bootstrap one Organization ID instead of all active Organizations}';

    protected $description = 'Idempotently bootstrap the finalized NewDay labor and dispatch Catalog';

    public function handle(NewDayCatalogBootstrap $bootstrap): int
    {
        $organizations = Organization::query()->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))->where('active', true)->orderBy('id')->get();
        if ($organizations->isEmpty()) {
            $this->error('No matching active Organization was found.');

            return self::FAILURE;
        }
        $conflicts = 0;
        foreach ($organizations as $organization) {
            $result = $bootstrap->bootstrap($organization);
            $this->info("Organization {$organization->id} ({$organization->name})");
            $this->line('  Created: '.($result['created'] ? implode(', ', $result['created']) : 'none'));
            $this->line('  Unchanged: '.($result['unchanged'] ? implode(', ', $result['unchanged']) : 'none'));
            if ($result['conflicts']) {
                $this->error('  Conflicts: '.implode(', ', $result['conflicts']).' (existing structure retained; review manually)');
                $conflicts += count($result['conflicts']);
            }
        }

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}
