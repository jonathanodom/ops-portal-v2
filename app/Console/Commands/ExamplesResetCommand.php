<?php

namespace App\Console\Commands;

use App\Support\LocalExamples\LocalExampleGuard;
use App\Support\LocalExamples\LocalExampleInventory;
use App\Support\LocalExamples\LocalExampleResetter;
use Illuminate\Console\Command;
use Throwable;

final class ExamplesResetCommand extends Command
{
    protected $signature = 'examples:reset {--organization= : Existing Organization ID} {--profile=small : small or full} {--confirm= : Required exact destructive confirmation}';

    protected $description = 'Back up, verify, reset local operational data, and rebuild an example profile';

    public function handle(LocalExampleGuard $guard, LocalExampleInventory $inventory, LocalExampleResetter $resetter): int
    {
        try {
            $organization = $guard->organization((int) $this->option('organization'));
            $guard->superAdmin($organization);
            $profile = $guard->profile((string) $this->option('profile'));
            $before = $inventory->inspect($organization);
            $this->table(['Operational table', 'Rows to delete'], collect($before['counts'])->map(fn ($count, $table) => [$table, $count])->values()->all());
            $result = $resetter->reset((int) $organization->id, $profile, (string) $this->option('confirm'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Local operational data reset and {$profile} examples created.");
        $this->line('Verified backup: '.$result['backup']);
        $this->line('SHA-256: '.$result['sha256']);

        return self::SUCCESS;
    }
}
