<?php

namespace App\Console\Commands;

use App\Domain\Commercial\ProposalDepositInvoiceWorkflow;
use App\Models\ProposalAcceptance;
use Illuminate\Console\Command;

class ReconcileCommercialDepositInvoices extends Command
{
    protected $signature = 'commercial:reconcile-deposit-invoices {--organization=}';

    protected $description = 'Idempotently create missing draft deposit invoices for accepted commercial proposals.';

    public function handle(ProposalDepositInvoiceWorkflow $workflow): int
    {
        $query = ProposalAcceptance::query();
        if ($organization = $this->option('organization')) {
            $query->where('organization_id', (int) $organization);
        }
        $created = 0;
        $skipped = 0;
        $query->with('publication.revision.document.opportunity.owner')->orderBy('id')->each(function (ProposalAcceptance $acceptance) use ($workflow, &$created, &$skipped): void {
            $first = $acceptance->milestones()->orderBy('sort_order')->first();
            $owner = $acceptance->publication->revision->document->opportunity->owner;
            if (! $first || $first->invoice_id || ! $owner || $owner->status !== 'active') {
                $skipped++;

                return;
            }
            $workflow->createForAcceptance($acceptance, $owner);
            $created++;
        });
        $this->info("Created {$created} deposit invoice(s); skipped {$skipped} acceptance(s).");

        return self::SUCCESS;
    }
}
