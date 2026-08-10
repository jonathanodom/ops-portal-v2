<?php

namespace App\Domain;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

class CustomerCreationWorkflow
{
    /**
     * @return array{customer: Customer, contact: ?Contact, location: ServiceLocation}
     */
    public function create(Organization $organization, User $actor, array $data, AuditRecorder $audit): array
    {
        return DB::transaction(function () use ($organization, $actor, $data, $audit): array {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id,
                'type' => $data['type'],
                'display_name' => $data['display_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'phone_normalized' => Phone::normalize($data['phone'] ?? null),
                'email' => $data['email'] ?? null,
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            $contact = null;
            if (filled($data['contact']['name'] ?? null)) {
                $contact = Contact::query()->create([
                    'organization_id' => $organization->id,
                    'customer_id' => $customer->id,
                    'name' => $data['contact']['name'],
                    'role' => $data['contact']['role'] ?? null,
                    'phone' => $data['contact']['phone'] ?? null,
                    'phone_normalized' => Phone::normalize($data['contact']['phone'] ?? null),
                    'email' => $data['contact']['email'] ?? null,
                    'is_preferred' => true,
                    'active' => true,
                    'created_by_id' => $actor->id,
                    'updated_by_id' => $actor->id,
                ]);
                $audit->record($organization, $actor, 'contact.created', $contact, ['customer_id' => $customer->id]);
            }

            $location = ServiceLocation::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'primary_contact_id' => $contact?->id,
                'name' => $data['location']['name'],
                'address_line_1' => $data['location']['address_line_1'],
                'address_line_2' => $data['location']['address_line_2'] ?? null,
                'city' => $data['location']['city'],
                'state' => strtoupper($data['location']['state']),
                'postal_code' => $data['location']['postal_code'],
                'timezone' => $data['location']['timezone'],
                'access_instructions' => $data['location']['access_instructions'] ?? null,
                'site_notes' => $data['location']['site_notes'] ?? null,
                'is_primary' => true,
                'active' => true,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            $audit->record($organization, $actor, 'customer.created', $customer, ['type' => $customer->type]);
            $audit->record($organization, $actor, 'service_location.created', $location, [
                'customer_id' => $customer->id,
                'primary_contact_id' => $contact?->id,
                'is_primary' => true,
            ]);

            return compact('customer', 'contact', 'location');
        });
    }
}
