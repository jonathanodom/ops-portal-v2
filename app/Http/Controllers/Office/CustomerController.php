<?php

namespace App\Http\Controllers\Office;

use App\Domain\CustomerCreationWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request, AuditRecorder $audit, CustomerCreationWorkflow $workflow): RedirectResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [Customer::class, $organization]);
        $data = $this->validateCreate($request);

        $customer = $workflow->create($organization, $request->user(), $data, $audit)['customer'];

        return redirect()->route('office.customers.show', $customer)->with('status', 'Customer and first service location created.');
    }

    public function show(Request $request, string $customer): View
    {
        $organization = $this->organization($request);
        $membership = $request->attributes->get('membership');
        $customer = $this->customer($request, $customer);
        Gate::authorize('view', $customer);
        $customer->load([
            'contacts' => fn ($query) => $query->orderByDesc('is_preferred')->orderByDesc('active')->orderBy('name'),
            'serviceLocations' => fn ($query) => $query->with('primaryContact')->orderByDesc('is_primary')->orderByDesc('active')->orderBy('name'),
            'serviceTickets' => fn ($query) => $query
                ->with([
                    'serviceLocation',
                    'visits' => fn ($visits) => $visits->with('assignments.membership.user')->orderByDesc('scheduled_start_at')->orderByDesc('id'),
                ])
                ->withCount('visits')
                ->latest('updated_at')->latest('id'),
        ]);
        if ($membership->hasCapability('projects.view')) {
            $today = CarbonImmutable::now($organization->timezone)->toDateString();
            $customer->load(['projects' => fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->with(['owner:id,name', 'serviceLocation:id,organization_id,customer_id,name'])
                ->withCount([
                    'tasks as open_tasks_count' => fn ($tasks) => $tasks->whereNotIn('status', ['done', 'canceled']),
                    'tasks as overdue_tasks_count' => fn ($tasks) => $tasks->whereNotIn('status', ['done', 'canceled'])->whereDate('due_on', '<', $today),
                    'tasks as blocked_tasks_count' => fn ($tasks) => $tasks->where('status', 'blocked'),
                ])
                ->orderByRaw("case status when 'active' then 1 when 'planning' then 2 when 'on_hold' then 3 when 'completed' then 4 when 'canceled' then 5 else 6 end")
                ->latest('updated_at')
                ->latest('id')]);
        }
        $invoices = collect();
        if ($membership->hasCapability('invoices.view')) {
            $customer->load(['invoices' => fn ($query) => $query
                ->with(['serviceTicket', 'serviceLocation', 'paymentTransactions'])
                ->latest('issued_at')->latest('created_at')->latest('id')]);
            $invoices = $customer->invoices;
        }
        if ($membership->hasCapability('subscriptions.view')) {
            $customer->load(['serviceEnrollments' => fn ($query) => $query->with('serviceLocation')->orderByRaw("case status when 'active' then 1 when 'paused' then 2 else 3 end")->orderByDesc('id')]);
        }

        return view('office.customers.show', compact('customer', 'invoices') + [
            'purposes' => config('service_tickets.purposes'),
            'billingDispositions' => config('service_tickets.billing_dispositions'),
        ]);
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
        if ($data['status'] === 'inactive' && $customer->serviceTickets()->whereNotIn('status', ['completed', 'canceled'])->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Cancel or complete this customer’s open service tickets before archiving the customer.',
            ]);
        }
        if ($data['status'] === 'inactive' && $customer->serviceEnrollments()->whereIn('status', ['active', 'paused'])->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Cancel this Customerâ€™s current recurring Service enrollments before archiving the Customer.',
            ]);
        }

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
