<?php

namespace App\Console\Commands;

use App\Support\LocalExamples\LocalExampleGuard;
use App\Support\LocalExamples\LocalExampleInventory;
use Illuminate\Console\Command;
use Throwable;

final class ExamplesInventoryCommand extends Command
{
    protected $signature = 'examples:inventory {--organization= : Existing Organization ID}';

    protected $description = 'Preview local operational data and example profile state without changing records';

    public function handle(LocalExampleGuard $guard, LocalExampleInventory $inventory): int
    {
        try {
            $organization = $guard->organization((int) $this->option('organization'));
            $admin = $guard->superAdmin($organization);
            $data = $inventory->inspect($organization);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Organization {$organization->id}: {$organization->name}");
        $this->line("Example actor: {$admin->user->email}");
        $this->table(['Operational table', 'Rows'], collect($data['counts'])->map(fn ($count, $table) => [$table, $count])->values()->all());
        $this->table(['Example marker', 'Rows'], collect($data['exampleCounts'])->map(fn ($count, $table) => [$table, $count])->values()->all());
        $this->line('Small profile: '.$inventory->status($organization, 'small'));
        $this->line('Full profile: '.$inventory->status($organization, 'full'));
        $this->line('Referenced private objects: '.$data['storage_objects']);
        $this->warn('examples:reset deletes all operational rows shown above for this Organization, while preserving identity, access, branding, settings, credentials, sequences, and non-example Catalog data.');

        return self::SUCCESS;
    }
}
