<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceLocationController extends Controller
{
    public function index(Request $request, CustomerDirectorySearch $directory): View
    {
        $organization = $this->organization($request);
        Gate::authorize('viewAny', [ServiceLocation::class, $organization]);

        return view('office.locations.index', [
            'locations' => $directory->locations(
                $organization,
                (string) $request->query('search', ''),
                active: match ($request->query('status')) {
                    'active' => true,
                    'inactive' => false,
                    default => null,
                },
            ),
        ]);
    }

    public function show(Request $request, string $location): View
    {
        $location = $this->location($request, $location);
        Gate::authorize('view', $location);
        $location->load(['customer', 'primaryContact']);

        return view('office.locations.show', compact('location'));
    }

    public function create(Request $request, string $customer): View
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('create', [ServiceLocation::class, $customer]);

        return view('office.locations.create', $this->formData($customer, $this->organization($request)));
    }

    public function store(Request $request, string $customer, AuditRecorder $audit): RedirectResponse
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('create', [ServiceLocation::class, $customer]);
        $data = $this->validated($request, $customer);

        $location = DB::transaction(function () use ($request, $customer, $data, $audit): ServiceLocation {
            $customer->serviceLocations()->lockForUpdate()->get();
            $makePrimary = $data['is_primary'] || ! $customer->serviceLocations()->where('active', true)->exists();
            if ($makePrimary) {
                $customer->serviceLocations()->update(['is_primary' => false]);
            }
            $location = $customer->serviceLocations()->create(array_merge($data, [
                'organization_id' => $customer->organization_id,
                'is_primary' => $makePrimary,
                'created_by_id' => $request->user()->id,
                'updated_by_id' => $request->user()->id,
            ]));
            $audit->record($this->organization($request), $request->user(), 'service_location.created', $location, [
                'customer_id' => $customer->id,
                'primary_contact_id' => $location->primary_contact_id,
                'is_primary' => $location->is_primary,
            ]);

            return $location;
        });

        return redirect()->route('office.locations.show', $location)->with('status', 'Service location added.');
    }

    public function edit(Request $request, string $location): View
    {
        $location = $this->location($request, $location);
        Gate::authorize('update', $location);

        return view('office.locations.edit', array_merge(
            compact('location'),
            $this->formData($location->customer, $this->organization($request)),
        ));
    }

    public function update(Request $request, string $location, AuditRecorder $audit): RedirectResponse
    {
        $location = $this->location($request, $location);
        Gate::authorize('update', $location);
        $data = $this->validated($request, $location->customer);
        if (! $data['active'] && $location->visits()->where('status', '!=', 'canceled')->exists()) {
            throw ValidationException::withMessages([
                'active' => 'Cancel or move this location’s active visits before archiving the location.',
            ]);
        }
        if (! $data['active'] && $location->serviceEnrollments()->whereIn('status', ['active', 'paused'])->exists()) {
            throw ValidationException::withMessages([
                'active' => 'Cancel or move this locationâ€™s current recurring Service enrollments before archiving it.',
            ]);
        }

        DB::transaction(function () use ($request, $location, $data, $audit): void {
            $location->customer->serviceLocations()->lockForUpdate()->get();
            if ($data['is_primary'] && $data['active']) {
                $location->customer->serviceLocations()->whereKeyNot($location->id)->update(['is_primary' => false]);
            }
            if (! $data['active']) {
                $data['is_primary'] = false;
            }

            $before = $location->getAttributes();
            $location->update(array_merge($data, ['updated_by_id' => $request->user()->id]));

            if (! $location->customer->serviceLocations()->where('active', true)->where('is_primary', true)->exists()) {
                $location->customer->serviceLocations()->where('active', true)->orderBy('id')->first()?->update(['is_primary' => true]);
            }

            $changed = array_keys(array_diff_assoc($location->getAttributes(), $before));
            $audit->record($this->organization($request), $request->user(), 'service_location.updated', $location, [
                'customer_id' => $location->customer_id,
                'primary_contact_id' => $location->primary_contact_id,
                'changed_fields' => array_values(array_diff($changed, ['updated_at'])),
            ]);
        });

        return redirect()->route('office.locations.show', $location)->with('status', 'Service location updated.');
    }

    private function validated(Request $request, Customer $customer): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'primary_contact_id' => ['nullable', Rule::exists('contacts', 'id')
                ->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)
                ->where('active', true)],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in(config('customers.states'))],
            'postal_code' => ['required', 'regex:/^\d{5}(?:-\d{4})?$/'],
            'timezone' => ['required', 'timezone'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'site_notes' => ['nullable', 'string', 'max:5000'],
            'is_primary' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['state'] = strtoupper($data['state']);
        $data['primary_contact_id'] = filled($data['primary_contact_id'] ?? null) ? (int) $data['primary_contact_id'] : null;
        $data['is_primary'] = $request->boolean('is_primary');
        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    private function formData(Customer $customer, Organization $organization): array
    {
        return [
            'customer' => $customer,
            'contacts' => $customer->contacts()->where('active', true)->orderByDesc('is_preferred')->orderBy('name')->get(),
            'states' => config('customers.states'),
            'defaultTimezone' => $organization->timezone,
        ];
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function customer(Request $request, string $id): Customer
    {
        return Customer::query()->forOrganization($this->organization($request)->id)->findOrFail($id);
    }

    private function location(Request $request, string $id): ServiceLocation
    {
        $organization = $this->organization($request);
        $location = ServiceLocation::query()->where('organization_id', $organization->id)->find($id);

        if (! $location) {
            if (ServiceLocation::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'service_location',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $location;
    }
}
