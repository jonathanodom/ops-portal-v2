<?php

namespace App\Domain\Commercial;

use App\Models\Invoice;
use App\Models\ProjectBillingMilestone;
use App\Models\ProjectMilestone;
use App\Models\User;

final class ProjectMilestoneBillingWorkflow
{
    public function __construct(private readonly ProposalDepositInvoiceWorkflow $invoices) {}

    public function createDraftForCompletedMilestone(ProjectMilestone $milestone, User $actor): ?Invoice
    {
        $mapping = ProjectBillingMilestone::query()
            ->where('organization_id', $milestone->organization_id)
            ->where('project_milestone_id', $milestone->id)
            ->with(['acceptedMilestone', 'commercialScope'])
            ->first();

        if (! $mapping || ($mapping->commercialScope->scope_type === 'change_order' && $mapping->contract_delta_cents <= 0)) {
            return null;
        }

        return $this->invoices->createForMilestone($mapping->acceptedMilestone, $actor);
    }
}
