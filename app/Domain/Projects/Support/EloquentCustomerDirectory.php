<?php

namespace App\Domain\Projects\Support;

use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Data\ContactSummary;
use App\Domain\Projects\Data\CustomerSummary;
use App\Domain\Projects\Data\LocationSummary;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use Illuminate\Support\Collection;

final class EloquentCustomerDirectory implements CustomerDirectory
{
    public function search(Organization $organization, ?string $search = null, int $limit = 100): Collection
    {
        return Customer::query()->forOrganization($organization->id)
            ->when(filled($search), fn ($query) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', '%'.trim((string) $search).'%')
                ->orWhere('legal_name', 'like', '%'.trim((string) $search).'%')))
            ->orderBy('display_name')->limit($limit)->get()->mapWithKeys(fn (Customer $customer) => [$customer->id => $this->customer($customer)]);
    }

    public function summaries(Organization $organization, array $ids): Collection
    {
        return Customer::query()->forOrganization($organization->id)->whereKey($ids)->get()->mapWithKeys(fn (Customer $customer) => [$customer->id => $this->customer($customer)]);
    }

    public function resolve(Organization $organization, int $customerId): CustomerSummary
    {
        return $this->customer(Customer::query()->forOrganization($organization->id)->findOrFail($customerId));
    }

    public function locations(Organization $organization, int $customerId): Collection
    {
        $this->resolve($organization, $customerId);

        return ServiceLocation::query()->where('organization_id', $organization->id)->where('customer_id', $customerId)->orderByDesc('active')->orderBy('name')->get()
            ->mapWithKeys(fn (ServiceLocation $location) => [$location->id => new LocationSummary($location->id, $location->customer_id, $location->name, $location->formattedAddress(), $location->active)]);
    }

    public function contacts(Organization $organization, int $customerId): Collection
    {
        $this->resolve($organization, $customerId);

        return Contact::query()->where('organization_id', $organization->id)->where('customer_id', $customerId)->orderByDesc('active')->orderBy('name')->get()
            ->mapWithKeys(fn (Contact $contact) => [$contact->id => new ContactSummary($contact->id, $contact->customer_id, $contact->name, $contact->role, $contact->active)]);
    }

    private function customer(Customer $customer): CustomerSummary
    {
        return new CustomerSummary($customer->id, $customer->display_name, $customer->legal_name, $customer->status);
    }
}
