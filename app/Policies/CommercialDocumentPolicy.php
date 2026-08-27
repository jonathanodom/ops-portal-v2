<?php

namespace App\Policies;

use App\Models\CommercialDocument;
use App\Models\Opportunity;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

final class CommercialDocumentPolicy
{
    use ChecksOrganizationCapability;

    public function view(User $user, CommercialDocument $document): bool
    {
        return $this->hasCapability($user, $document->organization_id, 'quotes.view');
    }

    public function create(User $user, Opportunity $opportunity): bool
    {
        return $this->hasCapability($user, $opportunity->organization_id, 'quotes.manage');
    }

    public function update(User $user, CommercialDocument $document): bool
    {
        return $this->hasCapability($user, $document->organization_id, 'quotes.manage');
    }

    public function viewCostMargin(User $user, CommercialDocument $document): bool
    {
        return $this->hasCapability($user, $document->organization_id, 'quotes.cost_margin.view');
    }
}
