<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request, CustomerDirectorySearch $directory): View
    {
        $organization = $this->organization($request);
        Gate::authorize('viewAny', [Customer::class, $organization]);

        return view('office.customers.index', [
            'customers' => $directory->customers(
                $organization,
                (string) $request->query('search', ''),
                $request->string('status')->value() ?: null,
                $request->string('type')->value() ?: null,
            ),
            'types' => config('customers.types'),
            'statuses' => config('customers.statuses'),
        ]);
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [Customer::class, $organization]);

        return view('office.customers.create', $this->formOptions($organization));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [Customer::class, $organization]);
        $data = $this->validateCreate($request);

        $customer = DB::transaction(function () use ($request, $organization, $data, $audit): Customer {
            $customer = Customer::query()->create([
                'organization_id' => $organization->id,
                'type' => $data['type'],
                'display_name' => $data['display_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'phone_normalized' => Phone::normalize($data['phone'] ?? null),
                'email' => $data['email'] ?? null,
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
                'created_by_id' => $request->user()->id,
                'updated_by_id' => $request->user()->id,
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
                    'created_by_id' => $request->user()->id,
                    'updated_by_id' => $request->user()->id,
                ]);
                $audit->record($organization, $request->user(), 'contact.created', $contact, ['customer_id' => $customer->id]);
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
                'created_by_id' => $request->user()->id,
                'updated_by_id' => $request->user()->id,
            ]);

            $audit->record($organization, $request->user(), 'customer.created', $customer, ['type' => $customer->type]);
            $audit->record($organization, $request->user(), 'service_location.created', $location, [
                'customer_id' => $customer->id,
                'primary_contact_id' => $contact?->id,
                'is_primary' => true,
            ]);

            return $customer;
        });

        return redirect()->route('office.customers.show', $customer)->with('status', 'Customer and first service location created.');
    }

    public function show(Request $request, string $customer): View
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('view', $customer);
        $customer->load([
            'contacts' => fn ($query) => $query->orderByDesc('is_preferred')->orderByDesc('active')->orderBy('name'),
            'serviceLocations' => fn ($query) => $query->with('primaryContact')->orderByDesc('is_primary')->orderByDesc('active')->orderBy('name'),
        ]);

        return view('office.customers.show', compact('customer'));
    }

    public function edit(Request $request, string $customer): View
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('update', $customer);

        return view('office.customers.edit', array_merge(
            compact('customer'),
            $this->formOptions($this->organization($request)),
        ));
    }

    public function update(Request $request, string $customer, AuditRecorder $audit): RedirectResponse
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('update', $customer);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('customers.types')))],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'status' => ['required', Rule::in(array_keys(config('customers.statuses')))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['phone_normalized'] = Phone::normalize($data['phone'] ?? null);
        $data['updated_by_id'] = $request->user()->id;

        $before = $customer->getAttributes();
        $customer->update($data);
        $changed = array_keys(array_diff_assoc($customer->getAttributes(), $before));
        $audit->record($this->organization($request), $request->user(), 'customer.updated', $customer, [
            'changed_fields' => array_values(array_diff($changed, ['phone_normalized', 'updated_at'])),
        ]);

        return redirect()->route('office.customers.show', $customer)->with('status', 'Customer updated.');
    }

    private function validateCreate(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(config('customers.types')))],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'status' => ['required', Rule::in(array_keys(config('customers.statuses')))],
            'notes' => ['nullable', 'string', 'max:5000'],
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
            'location.site_notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function customer(Request $request, string $id): Customer
    {
        $organization = $this->organization($request);
        $customer = Customer::query()->forOrganization($organization->id)->find($id);

        if (! $customer) {
            if (Customer::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'customer',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $customer;
    }

    private function formOptions(Organization $organization): array
    {
        return [
            'types' => config('customers.types'),
            'statuses' => config('customers.statuses'),
            'states' => config('customers.states'),
            'defaultTimezone' => $organization->timezone,
        ];
    }
}
