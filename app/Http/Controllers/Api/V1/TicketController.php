<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ServiceTicketCreationValidator;
use App\Domain\ServiceTicketCreator;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Support\Api\ApiResponse;
use App\Support\Api\IdempotencyStore;
use App\Support\Api\V1\TicketSummary;
use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    /** GET /api/v1/tickets — plan §8.3. */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate([
            'customer_id' => ['sometimes', Rule::exists('customers', 'id')->where('organization_id', $organization->id)],
            'status' => ['sometimes', Rule::in(array_keys(config('service_tickets.statuses')))],
            'priority' => ['sometimes', Rule::in(array_keys(config('service_tickets.priorities')))],
            'source' => ['sometimes', Rule::in(array_keys(config('service_tickets.sources')))],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $tickets = ServiceTicket::query()
            ->forOrganization($organization->id)
            ->when(isset($data['customer_id']), fn ($query) => $query->where('customer_id', $data['customer_id']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(isset($data['priority']), fn ($query) => $query->where('priority', $data['priority']))
            ->when(isset($data['source']), fn ($query) => $query->where('source', $data['source']))
            ->latest()
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return ApiResponse::success($request, $tickets->map(TicketSummary::make(...))->all());
    }

    /** GET /api/v1/tickets/{ticket_id} — plan §8.3. */
    public function show(Request $request, string $ticket): JsonResponse
    {
        return ApiResponse::success($request, TicketSummary::make($this->ticket($request, $ticket)));
    }

    /**
     * POST /api/v1/tickets — plan §8.3/§14.
     * Requires Idempotency-Key; reuses the exact domain services the office
     * ticket-creation controller uses (ServiceTicketCreationValidator +
     * ServiceTicketCreator), so business rules and audit logging are not
     * duplicated. Only the external `location_id` → internal
     * `service_location_id` field-name translation is API-specific.
     */
    public function store(
        Request $request,
        ServiceTicketCreationValidator $validator,
        ServiceTicketCreator $creator,
        IdempotencyStore $idempotency,
    ): JsonResponse {
        $organization = $this->organization($request);
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return ApiResponse::error(
                $request,
                'idempotency_key_required',
                'The Idempotency-Key header is required for this operation.',
                400,
            );
        }

        $route = (string) $request->route()->getName();
        $cached = $idempotency->find($organization, $route, $key);
        if ($cached && $cached->response_status > 0) {
            return ApiResponse::success($request, $cached->response_data, $cached->response_status);
        }

        if ($request->has('location_id') && ! $request->has('service_location_id')) {
            $request->merge(['service_location_id' => $request->input('location_id')]);
        }
        $data = $validator->validate($request, $organization);

        [$status, $body] = $idempotency->once($organization, $request->user(), $route, $key, function () use ($organization, $request, $data, $creator) {
            $ticket = $creator->create($organization, $request->user(), $data, createVisit: false, confirmConflicts: false);

            return [201, TicketSummary::make($ticket)];
        });

        return ApiResponse::success($request, $body, $status);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        $organization = $this->organization($request);
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->find($id);

        if (! $ticket) {
            if (ServiceTicket::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'service_ticket',
                    'record_id' => (int) $id,
                    'actor_type' => 'service_account',
                ]);
            }

            throw (new ModelNotFoundException)->setModel(ServiceTicket::class, [$id]);
        }

        return $ticket;
    }
}
