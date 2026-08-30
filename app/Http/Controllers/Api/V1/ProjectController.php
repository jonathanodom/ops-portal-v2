<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Projects\Actions\ProjectWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Api\ApiResponse;
use App\Support\Api\V1\ProjectSummary;
use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /** GET /api/v1/customers/{customer_id}/projects — plan §8.4. */
    public function forCustomer(Request $request, string $customer): JsonResponse
    {
        $organization = $this->organization($request);
        $customerModel = $this->customer($request, $customer);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(ProjectWorkflow::STATUSES)],
        ]);

        $projects = Project::query()
            ->forOrganization($organization->id)
            ->where('customer_id', $customerModel->id)
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->latest('updated_at')
            ->get();

        return ApiResponse::success($request, $projects->map(ProjectSummary::make(...))->all());
    }

    /** GET /api/v1/projects/{project_id} — plan §8.4. */
    public function show(Request $request, string $project): JsonResponse
    {
        return ApiResponse::success($request, ProjectSummary::make($this->project($request, $project)));
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function customer(Request $request, string $id): Customer
    {
        return Customer::query()->forOrganization($this->organization($request)->id)->findOrFail($id);
    }

    private function project(Request $request, string $id): Project
    {
        $organization = $this->organization($request);
        $project = Project::query()->forOrganization($organization->id)->find($id);

        if (! $project) {
            if (Project::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'project',
                    'record_id' => (int) $id,
                    'actor_type' => 'service_account',
                ]);
            }

            throw (new ModelNotFoundException)->setModel(Project::class, [$id]);
        }

        return $project;
    }
}
