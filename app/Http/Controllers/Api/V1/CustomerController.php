<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Support\Api\ApiResponse;
use App\Support\Api\V1\CustomerSummary;
use App\Support\Api\V1\LocationSummary;
use App\Support\AuditRecorder;
use App\Support\CustomerDirectorySearch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * GET /api/v1/customers/search — plan §8.1.
     * Reuses App\Support\CustomerDirectorySearch (same query the office
     * "quick add customer" ticket picker uses) rather than duplicating
     * business logic, per plan §10.
     */
    public function search(Request $request, CustomerDirectorySearch $directory): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $customers = $directory->ticketOptions(
            $this->organization($request),
            $data['q'],
            (int) ($data['limit'] ?? 20),
        );

        return ApiResponse::success($request, $customers->map(CustomerSummary::make(...))->all());
    }

    /** GET /api/v1/customers/{customer_id} — plan §8.1. */
    public function show(Request $request, string $customer): JsonResponse
    {
        return ApiResponse::success($request, CustomerSummary::make($this->customer($request, $customer)));
    }

    /** GET /api/v1/customers/{customer_id}/locations — plan §8.2. */
    public function locations(Request $request, string $customer): JsonResponse
    {
        $customer = $this->customer($request, $customer);
        $data = $request->validate(['active' => ['sometimes', 'boolean']]);

        $locations = ServiceLocation::query()
            ->where('customer_id', $customer->id)
            ->when(array_key_exists('active', $data), fn ($query) => $query->where('active', $data['active']))
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($request, $locations->map(LocationSummary::make(...))->all());
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
                    'actor_type' => 'service_account',
                ]);
            }

            throw (new ModelNotFoundException)->setModel(Customer::class, [$id]);
        }

        return $customer;
    }
}
