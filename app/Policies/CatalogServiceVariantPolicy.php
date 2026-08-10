<?php

namespace App\Policies;

use App\Models\CatalogServiceVariant;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;

class CatalogServiceVariantPolicy
{
    use ChecksOrganizationCapability;

    public function update(User $user, CatalogServiceVariant $variant): bool
    {
        return $this->hasCapability($user, $variant->organization_id, 'catalog.manage');
    }
}
