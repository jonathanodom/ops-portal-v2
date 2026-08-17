<?php

namespace App\Domain\Projects\Contracts;

use App\Domain\Projects\Data\ContactSummary;
use App\Domain\Projects\Data\CustomerSummary;
use App\Domain\Projects\Data\LocationSummary;
use App\Models\Organization;
use Illuminate\Support\Collection;

interface CustomerDirectory
{
    /** @return Collection<int, CustomerSummary> */
    public function search(Organization $organization, ?string $search = null, int $limit = 100): Collection;

    /** @return Collection<int, CustomerSummary> */
    public function summaries(Organization $organization, array $ids): Collection;

    public function resolve(Organization $organization, int $customerId): CustomerSummary;

    /** @return Collection<int, LocationSummary> */
    public function locations(Organization $organization, int $customerId): Collection;

    /** @return Collection<int, ContactSummary> */
    public function contacts(Organization $organization, int $customerId): Collection;
}
