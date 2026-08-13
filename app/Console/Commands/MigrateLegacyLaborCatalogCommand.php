<?php

namespace App\Console\Commands;

use App\Domain\LegacyLaborCatalogMigrator;
use App\Models\Organization;
use Illuminate\Console\Command;

class MigrateLegacyLaborCatalogCommand extends Command
{
    protected $signature = 'billing:migrate-legacy-labor {--organization= : Migrate one Organization ID instead of all active Organizations}';

    protected $description = 'Safely map default legacy labor rates to hourly Catalog services';

    public function handle(LegacyLaborCatalogMigrator $migrator): int
    {
        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))
            ->where('active', true)
            ->orderBy('id')
            ->get();
        if ($organizations->isEmpty()) {
            $this->error('No matching active Organization was found.');

            return self::FAILURE;
        }

        $conflicts = 0;
        foreach ($organizations as $organization) {
            $result = $migrator->migrate($organization);
            $message = "Organization {$organization->id} ({$organization->name}): ".str_replace('_', ' ', $result['status']);
            if ($result['catalog_service_code']) {
                $message .= " -> {$result['catalog_service_code']}";
            }
            $result['status'] === 'conflict' ? $this->error($message) : $this->info($message);
            foreach ($result['warnings'] as $warning) {
                $this->warn('  '.$warning);
            }
            $conflicts += $result['status'] === 'conflict' ? 1 : 0;
        }

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}
