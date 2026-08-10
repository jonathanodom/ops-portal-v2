<?php

namespace App\Console\Commands;

use App\Domain\PaymentWorkflow;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;
use Throwable;

class PaymentsReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile {--organization=} {--limit=100}';

    protected $description = 'Reconcile open payment attempts with their configured providers';

    public function handle(PaymentWorkflow $workflow): int
    {
        $attempts = PaymentAttempt::query()->with(['configuration', 'invoice.organization'])->whereIn('status', ['open', 'processing', 'unknown'])
            ->when($this->option('organization'), fn ($query, $id) => $query->where('organization_id', $id))->oldest()->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        $reconciled = 0;
        $failed = 0;
        foreach ($attempts as $attempt) {
            try {
                $workflow->reconcile($attempt);
                $reconciled++;
            } catch (Throwable) {
                $failed++;
            }
        }
        $this->info("Reconciled: {$reconciled}; failed safely: {$failed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
