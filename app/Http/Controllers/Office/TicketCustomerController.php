<?php

namespace App\Http\Controllers\Office;

use App\Domain\CustomerCreationWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use App\Support\CustomerSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TicketCustomerController extends Controller
{
    public function index(Request $request, CustomerDirectorySearch $directory, CustomerSelection $selection): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [ServiceTicket::class, $organization]);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        $customers = $directory->ticketOptions($organization, $data['q'] ?? '')
            ->map(fn (Customer $customer): array => $selection->present($customer));

        return response()->json(['customers' => $customers])->header('Cache-Control', 'no-store');
    }

    public function store(
        Request $request,
        CustomerCreationWorkflow $workflow,
        AuditRecorder $audit,
        CustomerSelection $selection,
    ): JsonResponse {
        $organization = $this->organization($request);
        Gate::authorize('create', [ServiceTicket::class, $organization]);
        Gate::authorize('create', [Customer::class, $organization]);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('customers.types')))],
            'display_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'contact.name' => ['nullable', 'required_with:contact.role,contact.phone,contact.email', 'string', 'max:255'],
            'contact.role' => ['nullable', 'string', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.email' => ['nullable', 'email:rfc', 'max:255'],
            'location.name' => ['required', 'string', 'max:255'],
            'location.address_line_1' => ['required', 'string', 'max:255'],
            'location.address_line_2' => ['nullable', 'string', 'max:255'],
            'location.city' => ['required', 'string', 'max:100'],
            'location.state' => ['required', Rule::in(config('customers.states'))],
            'location.postal_code' => ['required', 'regex:/^\d{5}(?:-\d{4})?$/'],
            'location.timezone' => ['required', 'timezone'],
            'location.access_instructions' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['status'] = 'active';

        $created = $workflow->create($organization, $request->user(), $data, $audit);
        $created['customer']->setRelation('contacts', collect(array_filter([$created['contact']])));
        $created['customer']->setRelation('serviceLocations', collect([$created['location']]));

        return response()->json([
            'message' => 'Customer and service location created and selected.',
            'customer' => $selection->present($created['customer']),
        ], 201)->header('Cache-Control', 'no-store');
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
