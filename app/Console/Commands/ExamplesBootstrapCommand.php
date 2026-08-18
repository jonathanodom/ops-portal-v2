<?php

namespace App\Console\Commands;

use App\Support\LocalExamples\LocalExampleBootstrapper;
use App\Support\LocalExamples\LocalExampleGuard;
use App\Support\LocalExamples\LocalExampleInventory;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ExamplesBootstrapCommand extends Command
{
    protected $signature = 'examples:bootstrap {--organization= : Existing Organization ID} {--profile=small : small or full}';

    protected $description = 'Add a guarded local example profile without resetting Organization configuration';

    public function handle(LocalExampleGuard $guard, LocalExampleInventory $inventory, LocalExampleBootstrapper $bootstrapper): int
    {
        try {
            $organization = $guard->organization((int) $this->option('organization'));
            $guard->superAdmin($organization);
            $profile = $guard->profile((string) $this->option('profile'));
            $status = $inventory->status($organization, $profile);
            if ($status === 'complete') {
                $this->info("The {$profile} example profile is already complete; no records were changed.");

                return self::SUCCESS;
            }
            if ($status === 'partial') {
                throw new RuntimeException('A partial or different example profile is present. Run examples:reset with explicit confirmation.');
            }
            $counts = $bootstrapper->bootstrap($organization, $profile);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Local {$profile} examples created for {$organization->name}.");
        $this->table(['Record', 'Count'], collect($counts)->map(fn ($count, $table) => [$table, $count])->values()->all());

        return self::SUCCESS;
    }
}
