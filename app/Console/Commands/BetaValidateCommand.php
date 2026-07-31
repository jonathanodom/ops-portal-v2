<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BetaValidateCommand extends Command
{
    protected $signature = 'beta:validate';

    protected $description = 'Validate deterministic Phase 5 beta fixtures and integrity';

    public function handle(): int
    {
        if (! app()->environment('beta')) {
            $this->error('Run beta validation with --env=beta.');

            return self::FAILURE;
        }

        $expected = [
            'organizations' => 1, 'users' => 5, 'customers' => 250, 'service_locations' => 400,
            'service_tickets' => 500, 'visits' => 1000, 'closeouts' => 200, 'visit_media' => 500,
        ];
        $failed = false;
        foreach ($expected as $table => $count) {
            $actual = DB::table($table)->count();
            $this->line("{$table}: {$actual} / {$count}");
            $failed = $failed || $actual !== $count;
        }
        $scenarioTickets = DB::table('service_tickets')->where('title', 'like', 'BETA %:%')->count();
        $roles = DB::table('organization_membership_role')->count();
        $this->line("scenario_tickets: {$scenarioTickets} / 3");
        $this->line("role_assignments: {$roles} / 5");
        $failed = $failed || $scenarioTickets !== 3 || $roles !== 5;

        if (config('database.default') === 'sqlite') {
            $integrity = DB::selectOne('PRAGMA integrity_check')->integrity_check;
            $this->line("sqlite_integrity: {$integrity}");
            $failed = $failed || $integrity !== 'ok';
        }

        $failed ? $this->error('Beta fixture validation failed.') : $this->info('Beta fixture validation passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
