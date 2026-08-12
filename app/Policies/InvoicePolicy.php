<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class InvoicePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->hasCapability($user, $organization->id, 'invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.view');
    }

    public function manage(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.manage');
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.issue');
    }

    public function present(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.present');
    }

    public function discount(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.discount');
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.void');
    }

    public function deleteDraft(User $user, Invoice $invoice): bool
    {
        return $this->hasCapability($user, $invoice->organization_id, 'invoices.delete_draft');
    }
}
