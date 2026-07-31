<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CustomerDirectoryController extends Controller
{
    public function index(Request $request, CustomerDirectorySearch $directory): View
    {
        $organization = $this->organization($request);
        Gate::authorize('viewAny', [Customer::class, $organization]);
        $search = (string) $request->query('search', '');

        return view('field.customers.index', [
            'customers' => $directory->customers($organization, $search, activeOnly: true),
            'locations' => $directory->locations($organization, $search, activeOnly: true),
            'search' => $search,
        ]);
    }

    public function showCustomer(Request $request, string $customer): View
    {
        $customer = Customer::query()
            ->forOrganization($this->organization($request)->id)
            ->where('status', 'active')
            ->with([
                'preferredContact' => fn ($query) => $query->where('active', true),
                'serviceLocations' => fn ($query) => $query->where('active', true)->with('primaryContact')->orderByDesc('is_primary')->orderBy('name'),
            ])->find($customer);
        if (! $customer) {
            $this->auditCrossOrganizationAttempt($request, Customer::class, 'customer', (int) $request->route('customer'));
            abort(404);
        }
        Gate::authorize('view', $customer);

        return view('field.customers.show', compact('customer'));
    }

    public function showLocation(Request $request, string $location): View
    {
        $location = ServiceLocation::query()
            ->where('organization_id', $this->organization($request)->id)
            ->where('active', true)
            ->whereHas('customer', fn ($query) => $query->where('status', 'active'))
            ->with(['customer', 'primaryContact' => fn ($query) => $query->where('active', true)])
            ->find($location);
        if (! $location) {
            $this->auditCrossOrganizationAttempt($request, ServiceLocation::class, 'service_location', (int) $request->route('location'));
            abort(404);
        }
        Gate::authorize('view', $location);

        return view('field.locations.show', compact('location'));
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /** @param class-string<Customer|ServiceLocation> $model */
    private function auditCrossOrganizationAttempt(Request $request, string $model, string $type, int $id): void
    {
        if ($model::query()->whereKey($id)->exists()) {
            $organization = $this->organization($request);
            app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                'record_type' => $type,
                'record_id' => $id,
            ]);
        }
    }
}
