<?php

namespace App\Console\Commands;

use Database\Seeders\BetaScenarioSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class BetaSetupCommand extends Command
{
    protected $signature = 'beta:setup';

    protected $description = 'Reset and seed only the isolated Phase 5 beta database';

    public function handle(): int
    {
        try {
            $this->guardBetaDatabase();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (blank(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
            $this->line(Artisan::output());
        }
        if (config('database.default') === 'sqlite') {
            $path = $this->sqlitePath();
            if (! is_file($path)) {
                touch($path);
            }
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->line(Artisan::output());
        Artisan::call('db:seed', ['--class' => BetaScenarioSeeder::class, '--force' => true]);
        $this->line(Artisan::output());
        $this->info('Isolated beta database ready. No active development records were changed.');

        return self::SUCCESS;
    }

    private function guardBetaDatabase(): void
    {
        if (! app()->environment('beta')) {
            throw new \RuntimeException('Refusing beta reset unless APP_ENV=beta. Run with --env=beta.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! Str::contains(Str::lower($database), 'beta')) {
            throw new \RuntimeException('Refusing beta reset because the configured database is not explicitly beta-scoped.');
        }

        if (config('database.default') === 'sqlite') {
            $resolved = str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $database)
                ? $database : base_path($database);
            $allowed = realpath(database_path()) ?: database_path();
            $parent = realpath(dirname($resolved)) ?: dirname($resolved);
            $allowedPrefix = rtrim(strtolower($allowed), '\\/').DIRECTORY_SEPARATOR;
            if (! str_starts_with(rtrim(strtolower($parent), '\\/').DIRECTORY_SEPARATOR, $allowedPrefix)) {
                throw new \RuntimeException('The beta SQLite database must remain inside the application database directory.');
            }
        }
    }

    private function sqlitePath(): string
    {
        $database = (string) config('database.connections.sqlite.database');

        return str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $database)
            ? $database : base_path($database);
    }
}
