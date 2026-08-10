<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CustomerDirectorySearch
{
    public function ticketOptions(Organization $organization, string $search, int $limit = 10): Collection
    {
        $term = trim($search);
        if ($term === '') {
            return collect();
        }

        $digits = Phone::normalize($term);
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Customer::query()
            ->forOrganization($organization->id)
            ->where('status', 'active')
            ->with([
                'contacts' => fn ($query) => $query->where('active', true)->orderByDesc('is_preferred')->orderBy('name'),
                'serviceLocations' => fn ($query) => $query->where('active', true)->orderByDesc('is_primary')->orderBy('name'),
            ])
            ->where(function ($query) use ($like, $digits): void {
                $query->where('display_name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('contacts', fn ($contact) => $contact
                        ->where('active', true)
                        ->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                    ->orWhereHas('serviceLocations', fn ($location) => $location
                        ->where('active', true)
                        ->where(fn ($inner) => $inner->where('name', 'like', $like)
                            ->orWhere('address_line_1', 'like', $like)
                            ->orWhere('address_line_2', 'like', $like)
                            ->orWhere('city', 'like', $like)
                            ->orWhere('state', 'like', $like)
                            ->orWhere('postal_code', 'like', $like)));

                if ($digits !== null) {
                    $query->orWhere('phone_normalized', 'like', '%'.$digits.'%')
                        ->orWhereHas('contacts', fn ($contact) => $contact
                            ->where('active', true)
                            ->where('phone_normalized', 'like', '%'.$digits.'%'));
                }
            })
            ->orderBy('display_name')
            ->limit(max(1, min($limit, 20)))
            ->get();
    }

    public function customers(
        Organization $organization,
        string $search = '',
        ?string $status = null,
        ?string $type = null,
        bool $activeOnly = false,
    ): LengthAwarePaginator {
        $term = trim($search);
        $digits = Phone::normalize($term);

        return Customer::query()
            ->forOrganization($organization->id)
            ->withCount([
                'contacts',
                'serviceLocations' => fn ($query) => $query->when($activeOnly, fn ($query) => $query->where('active', true)),
            ])
            ->with(['preferredContact', 'serviceLocations' => fn ($query) => $query->where('active', true)->orderByDesc('is_primary')])
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($term !== '', function ($query) use ($term, $digits): void {
                $like = '%'.$term.'%';
                $query->where(function ($query) use ($like, $digits): void {
                    $query->where('display_name', 'like', $like)
                        ->orWhere('legal_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhereHas('contacts', fn ($contact) => $contact
                            ->where('active', true)
                            ->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like)))
                        ->orWhereHas('serviceLocations', fn ($location) => $location
                            ->where(fn ($inner) => $inner->where('name', 'like', $like)
                                ->orWhere('address_line_1', 'like', $like)
                                ->orWhere('address_line_2', 'like', $like)
                                ->orWhere('city', 'like', $like)
                                ->orWhere('state', 'like', $like)
                                ->orWhere('postal_code', 'like', $like)));
                    $query->orWhereHas('serviceTickets', fn ($ticket) => $ticket->where('ticket_number', 'like', $like));

                    if ($digits !== null) {
                        $query->orWhere('phone_normalized', 'like', '%'.$digits.'%')
                            ->orWhereHas('contacts', fn ($contact) => $contact->where('phone_normalized', 'like', '%'.$digits.'%'));
                    }
                });
            })
            ->orderBy('display_name')
            ->paginate(20, ['*'], 'customers')
            ->withQueryString();
    }

    public function locations(
        Organization $organization,
        string $search = '',
        bool $activeOnly = false,
        ?bool $active = null,
    ): LengthAwarePaginator {
        $term = trim($search);

        return ServiceLocation::query()
            ->where('organization_id', $organization->id)
            ->with(['customer', 'primaryContact'])
            ->when($activeOnly, fn ($query) => $query->where('active', true)
                ->whereHas('customer', fn ($customer) => $customer->where('status', 'active')))
            ->when($active !== null, fn ($query) => $query->where('active', $active))
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$term.'%';
                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('address_line_1', 'like', $like)
                    ->orWhere('address_line_2', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('state', 'like', $like)
                    ->orWhere('postal_code', 'like', $like)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'like', $like)));
            })
            ->orderBy('name')
            ->paginate(20, ['*'], 'locations')
            ->withQueryString();
    }
}
