<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;

class OpsBackupCommand extends Command
{
    protected $signature = 'ops:backup {--output=}';

    protected $description = 'Create a consistent database backup without changing application data';

    public function handle(): int
    {
        $driver = config('database.default');
        $extension = $driver === 'sqlite' ? 'sqlite' : 'sql';
        $output = $this->option('output') ?: storage_path('app/backups/ops-'.now()->format('Ymd-His').'.'.$extension);
        File::ensureDirectoryExists(dirname($output));

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA wal_checkpoint(FULL)');
            $database = (string) config('database.connections.sqlite.database');
            $source = $this->absoluteSqlitePath($database);
            File::copy($source, $output);
        } elseif ($driver === 'mysql') {
            $config = config('database.connections.mysql');
            $process = new Process(['mysqldump', '--single-transaction', '--skip-lock-tables', '-h', $config['host'], '-P', (string) $config['port'], '-u', $config['username'], $config['database']]);
            $process->setEnv(['MYSQL_PWD' => (string) $config['password']]);
            $process->mustRun();
            File::put($output, $process->getOutput());
        } else {
            $this->error("Unsupported backup driver: {$driver}");

            return self::FAILURE;
        }

        File::put($output.'.manifest.json', json_encode($this->snapshot(DB::connection()->getPdo(), $driver), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info($output);
        $this->line('SHA-256: '.hash_file('sha256', $output));
        $this->line('Integrity manifest: '.$output.'.manifest.json');

        return self::SUCCESS;
    }

    private function absoluteSqlitePath(string $database): string
    {
        return str_starts_with($database, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $database)
            ? $database : base_path($database);
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
            'invoice_ticket' => ['invoices', 'service_tickets', 'SELECT COUNT(*) FROM invoices child LEFT JOIN service_tickets parent ON parent.id = child.service_ticket_id WHERE parent.id IS NULL'],
            'invoice_handoff' => ['invoices', 'billing_handoffs', 'SELECT COUNT(*) FROM invoices child LEFT JOIN billing_handoffs parent ON parent.id = child.billing_handoff_id WHERE parent.id IS NULL'],
            'invoice_line' => ['invoice_lines', 'invoices', 'SELECT COUNT(*) FROM invoice_lines child LEFT JOIN invoices parent ON parent.id = child.invoice_id WHERE parent.id IS NULL'],
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
                'invoice_chain' => ($counts['invoices'] ?? 0) === 0 || (($relationships['invoice_ticket'] ?? 0) === 0 && ($relationships['invoice_handoff'] ?? 0) === 0 && ($relationships['invoice_line'] ?? 0) === 0),
            ],
        ];
    }
}
