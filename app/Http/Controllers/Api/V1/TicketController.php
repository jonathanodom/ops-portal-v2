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
use Illuminate\Validation\ValidationException;

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
        $key = $this->requireIdempotencyKey($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $route = $this->idempotencyRoute($request);
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

    /**
     * PATCH /api/v1/tickets/{ticket_id} — plan §8.3/§14.
     * Only the two purpose-built fields the plan documents: `priority`
     * (direct set) and `description_append` (never overwrites the
     * existing description). Status transitions are deliberately not
     * exposed here — App\Domain\ServiceTicketWorkflow enforces workflow
     * rules (allowed transitions, required reasons, active-timer
     * confirmation) that a generic PATCH would bypass; JARVIS does not
     * get a status-transition scope in this work package.
     * Requires Idempotency-Key: description_append is not naturally
     * idempotent (a retried append would duplicate text) without it.
     */
    public function update(Request $request, string $ticket, IdempotencyStore $idempotency): JsonResponse
    {
        $organization = $this->organization($request);
        $key = $this->requireIdempotencyKey($request);
        if ($key instanceof JsonResponse) {
            return $key;
        }

        $route = $this->idempotencyRoute($request);
        $cached = $idempotency->find($organization, $route, $key);
        if ($cached && $cached->response_status > 0) {
            return ApiResponse::success($request, $cached->response_data, $cached->response_status);
        }

        $ticketModel = $this->ticket($request, $ticket);
        $data = $request->validate([
            'priority' => ['sometimes', Rule::in(array_keys(config('service_tickets.priorities')))],
            'description_append' => ['sometimes', 'string', 'min:1', 'max:5000'],
        ]);
        if (! array_key_exists('priority', $data) && ! array_key_exists('description_append', $data)) {
            throw ValidationException::withMessages([
                'priority' => 'At least one of priority or description_append is required.',
            ]);
        }

        [$status, $body] = $idempotency->once($organization, $request->user(), $route, $key, function () use ($organization, $request, $ticketModel, $data) {
            $before = $ticketModel->getAttributes();
            $update = ['updated_by_id' => $request->user()->id];
            if (array_key_exists('priority', $data)) {
                $update['priority'] = $data['priority'];
            }
            if (array_key_exists('description_append', $data)) {
                $appended = trim($data['description_append']);
                $update['description'] = $ticketModel->description ? $ticketModel->description."\n\n".$appended : $appended;
            }

            $ticketModel->update($update);
            $changed = array_values(array_diff(array_keys(array_diff_assoc($ticketModel->getAttributes(), $before)), ['updated_at']));
            app(AuditRecorder::class)->record($organization, $request->user(), 'service_ticket.updated', $ticketModel, [
                'changed_fields' => $changed,
                'actor_type' => 'service_account',
            ]);

            return [200, TicketSummary::make($ticketModel->refresh())];
        });

        return ApiResponse::success($request, $body, $status);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /** Scopes idempotency by the exact method+path, so a key can never be replayed across two different resources. */
    private function idempotencyRoute(Request $request): string
    {
        return $request->method().' '.$request->path();
    }

    private function requireIdempotencyKey(Request $request): string|JsonResponse
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return ApiResponse::error(
                $request,
                'idempotency_key_required',
                'The Idempotency-Key header is required for this operation.',
                400,
            );
        }

        return $key;
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
