<?php

namespace App\Support;

use App\Models\Customer;

class CustomerSelection
{
    /** @return array<string, mixed> */
    public function present(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'display_name' => $customer->display_name,
            'secondary' => $customer->phone ?: $customer->email,
            'contacts' => $customer->contacts->map(fn ($contact): array => [
                'id' => $contact->id,
                'name' => $contact->name,
                'is_preferred' => (bool) $contact->is_preferred,
            ])->values(),
            'locations' => $customer->serviceLocations->map(fn ($location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => implode(', ', array_filter([
                    $location->address_line_1,
                    $location->city,
                    $location->state.' '.$location->postal_code,
                ])),
                'timezone' => $location->timezone,
                'is_primary' => (bool) $location->is_primary,
                'primary_contact_id' => $location->primary_contact_id,
            ])->values(),
        ];
    }
}
