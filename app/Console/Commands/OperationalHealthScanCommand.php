<?php

namespace App\Console\Commands;

use App\Models\OperationalIncident;
use App\Models\Organization;
use App\Support\OperationalHealthScan;
use Illuminate\Console\Command;

class OperationalHealthScanCommand extends Command
{
    protected $signature = 'ops:health-scan {--organization=} {--fail-on-open}';

    protected $description = 'Scan operational invariants and record safe incidents';

    public function handle(OperationalHealthScan $scan): int
    {
        $organizations = Organization::query()->where('active', true)
            ->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))->get();
        foreach ($organizations as $organization) {
            $result = $scan->scan($organization);
            $this->line($organization->name.': '.json_encode($result));
        }

        $open = OperationalIncident::query()->where('status', 'open')->count();
        $this->info("Open incidents: {$open}");

        return $this->option('fail-on-open') && $open > 0 ? self::FAILURE : self::SUCCESS;
    }
}
