<?php

namespace App\Domain;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\ServiceLocation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ServiceTicketCreationValidator
{
    public function validate(Request $request, Organization $organization, ?int $fixedCustomerId = null): array
    {
        if ($fixedCustomerId !== null && $request->filled('customer_id') && $request->integer('customer_id') !== $fixedCustomerId) {
            throw ValidationException::withMessages(['customer_id' => 'The Service Ticket customer is fixed by the Project.']);
        }

        if ($fixedCustomerId !== null) {
            $request->merge(['customer_id' => $fixedCustomerId]);
        }

        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('organization_id', $organization->id)->where('status', 'active')],
            'service_location_id' => ['required', Rule::exists('service_locations', 'id')->where('organization_id', $organization->id)->where('active', true)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', $organization->id)->where('active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'customer_visible_summary' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(array_keys(config('service_tickets.priorities')))],
            'source' => ['required', Rule::in(array_keys(config('service_tickets.sources')))],
            'purpose' => ['sometimes', Rule::in(array_keys(config('service_tickets.purposes')))],
            'billing_disposition' => ['sometimes', Rule::in(array_keys(config('service_tickets.billing_dispositions')))],
            'create_visit' => ['nullable', 'boolean'],
            'scheduled_start' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'scheduled_end' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => ['integer'],
            'lead_membership_id' => ['nullable', 'integer'],
            'confirm_location_mismatch' => ['sometimes', 'boolean'],
        ]);

        $location = ServiceLocation::query()->where('organization_id', $organization->id)->findOrFail($data['service_location_id']);
        if ((int) $location->customer_id !== (int) $data['customer_id']) {
            throw ValidationException::withMessages(['service_location_id' => 'The location must belong to the selected customer.']);
        }
        if (filled($data['contact_id'] ?? null) && ! Contact::query()->whereKey($data['contact_id'])->where('organization_id', $organization->id)->where('customer_id', $data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'The contact must belong to the selected customer.']);
        }

        return $data + [
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
        ];
    }
}
