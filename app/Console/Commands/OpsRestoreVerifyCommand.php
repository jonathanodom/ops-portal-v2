<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;

class OpsRestoreVerifyCommand extends Command
{
    protected $signature = 'ops:restore-verify {backup}';

    protected $description = 'Verify a backup by restoring only to an isolated temporary database';

    public function handle(): int
    {
        $backup = realpath($this->argument('backup'));
        if (! $backup || ! is_file($backup)) {
            $this->error('Backup file not found.');

            return self::FAILURE;
        }

        if (str_ends_with(strtolower($backup), '.sqlite')) {
            $temporary = database_path('restore-verify-'.bin2hex(random_bytes(4)).'-beta.sqlite');
            File::copy($backup, $temporary);
            try {
                $pdo = new PDO('sqlite:'.$temporary);
                $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
                $tables = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
                $this->line("Integrity: {$integrity}; tables: {$tables}");

                if ($integrity !== 'ok' || $tables === 0 || ! $this->verifyManifest($pdo, 'sqlite', $backup)) {
                    return self::FAILURE;
                }

                return self::SUCCESS;
            } finally {
                $pdo = null;
                File::delete($temporary);
            }
        }

        if (config('database.default') !== 'mysql') {
            $this->error('MySQL dump verification requires an active MySQL connection.');

            return self::FAILURE;
        }

        $config = config('database.connections.mysql');
        $temporary = $config['database'].'_restore_verify_'.bin2hex(random_bytes(3));
        if (! str_contains(strtolower($temporary), 'restore_verify')) {
            throw new \RuntimeException('Unsafe temporary restore database name.');
        }
        DB::statement("CREATE DATABASE `{$temporary}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $process = new Process(['mysql', '-h', $config['host'], '-P', (string) $config['port'], '-u', $config['username'], $temporary]);
            $process->setEnv(['MYSQL_PWD' => (string) $config['password']]);
            $stream = fopen($backup, 'rb');
            try {
                $process->setInput($stream);
                $process->mustRun();
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $count = DB::selectOne('SELECT COUNT(*) AS aggregate FROM information_schema.tables WHERE table_schema = ?', [$temporary])->aggregate;
            $this->line("Restored tables: {$count}");

            $pdo = new PDO(
                "mysql:host={$config['host']};port={$config['port']};dbname={$temporary};charset=utf8mb4",
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            return $count > 0 && $this->verifyManifest($pdo, 'mysql', $backup) ? self::SUCCESS : self::FAILURE;
        } finally {
            DB::statement("DROP DATABASE `{$temporary}`");
        }
    }

    private function verifyManifest(PDO $pdo, string $driver, string $backup): bool
    {
        $manifestPath = $backup.'.manifest.json';
        if (! is_file($manifestPath)) {
            $this->warn('No integrity manifest accompanied this legacy backup; structural checks only.');

            return true;
        }

        $expected = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $actual = $this->snapshot($pdo, $driver);
        if ($actual !== $expected) {
            $this->error('Restore manifest comparison failed. Table counts, migrations, or workflow relationships differ.');

            return false;
        }
        if (array_filter($actual['relationships']) || in_array(false, $actual['representative_workflows'], true)) {
            $this->error('Restore contains broken key workflow relationships.');

            return false;
        }

        $this->info('Manifest comparison passed: migrations, table counts, relationships, and representative workflows match.');

        return true;
    }

    private function snapshot(PDO $pdo, string $driver): array
    {
        $tables = $driver === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
            : array_map(fn (array $row): string => (string) array_values($row)[0], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_ASSOC));
        $quote = fn (string $table): string => $driver === 'mysql' ? "`{$table}`" : '"'.$table.'"';
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM '.$quote($table))->fetchColumn();
        }

        $relationships = [];
        $checks = [
            'ticket_customer' => ['service_tickets', 'customers', 'SELECT COUNT(*) FROM service_tickets child LEFT JOIN customers parent ON parent.id = child.customer_id WHERE parent.id IS NULL'],
            'ticket_location' => ['service_tickets', 'service_locations', 'SELECT COUNT(*) FROM service_tickets child LEFT JOIN service_locations parent ON parent.id = child.service_location_id WHERE parent.id IS NULL'],
            'visit_ticket' => ['visits', 'service_tickets', 'SELECT COUNT(*) FROM visits child LEFT JOIN service_tickets parent ON parent.id = child.service_ticket_id WHERE parent.id IS NULL'],
            'closeout_visit' => ['closeouts', 'visits', 'SELECT COUNT(*) FROM closeouts child LEFT JOIN visits parent ON parent.id = child.visit_id WHERE parent.id IS NULL'],
            'handoff_ticket' => ['billing_handoffs', 'service_tickets', 'SELECT COUNT(*) FROM billing_handoffs child LEFT JOIN service_tickets parent ON parent.id = child.service_ticket_id WHERE parent.id IS NULL'],
        ];
        foreach ($checks as $name => [$child, $parent, $sql]) {
            if (in_array($child, $tables, true) && in_array($parent, $tables, true)) {
                $relationships[$name] = (int) $pdo->query($sql)->fetchColumn();
            }
        }

        return [
            'tables' => $counts,
            'migration_count' => $counts['migrations'] ?? 0,
            'relationships' => $relationships,
            'representative_workflows' => [
                'ticket_context' => ($counts['service_tickets'] ?? 0) === 0 || (($relationships['ticket_customer'] ?? 0) === 0 && ($relationships['ticket_location'] ?? 0) === 0),
                'closeout_visit' => ($counts['closeouts'] ?? 0) === 0 || ($relationships['closeout_visit'] ?? 0) === 0,
                'handoff_ticket' => ($counts['billing_handoffs'] ?? 0) === 0 || ($relationships['handoff_ticket'] ?? 0) === 0,
            ],
        ];
    }
}
