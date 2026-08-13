<?php

namespace App\Http\Controllers\Office;

use App\Domain\VisitArchiveWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VisitArchiveController extends Controller
{
    public function index(Request $request, VisitArchiveWorkflow $workflow): View
    {
        $organization = $request->attributes->get('organization');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['planned', 'scheduled', 'assigned', 'canceled'])],
            'archived_from' => ['nullable', 'date_format:Y-m-d'],
            'archived_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $visits = Visit::onlyTrashed()->forOrganization($organization->id)
            ->with(['serviceTicket.customer', 'serviceLocation', 'archivedBy'])
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $query->where(function ($inner) use ($search): void {
                    $inner->where('ticket_visit_number', is_numeric($search) ? (int) $search : 0)
                        ->orWhereHas('serviceTicket', fn ($ticket) => $ticket
                            ->where('ticket_number', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'like', "%{$search}%")));
                });
            })
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['archived_from'] ?? null), fn ($query) => $query->whereDate('deleted_at', '>=', $filters['archived_from']))
            ->when(filled($filters['archived_to'] ?? null), fn ($query) => $query->whereDate('deleted_at', '<=', $filters['archived_to']))
            ->latest('deleted_at')->paginate(20)->withQueryString();

        return view('office.admin.archive', [
            'visits' => $visits,
            'purgeableVisitIds' => $visits->getCollection()->filter(fn (Visit $visit) => $workflow->canPurge($visit))->pluck('id')->all(),
        ]);
    }

    public function archive(Request $request, string $visit, VisitArchiveWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit, false);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'confirm_archive' => ['required', 'accepted'],
        ]);
        try {
            $workflow->archive($visit, $request->user(), $data['reason']);
        } catch (ValidationException $exception) {
            $this->recordRejected($request, $visit, 'archive', $exception);
            throw $exception;
        }

        return redirect()->route('office.service-tickets.show', $visit->service_ticket_id)->with('status', 'Visit moved to Admin Archive. It no longer appears in operational queues or blocks ticket completion.');
    }

    public function restore(Request $request, string $visit, VisitArchiveWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit, true);
        try {
            $workflow->restore($visit, $request->user());
        } catch (ValidationException $exception) {
            $this->recordRejected($request, $visit, 'restore', $exception);
            throw $exception;
        }

        return back()->with('status', $visit->displayNumber().' restored.');
    }

    public function destroy(Request $request, string $visit, VisitArchiveWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit, true);
        $displayNumber = $visit->displayNumber();
        $request->validate(['confirm_visit_id' => ['required', 'integer', Rule::in([$visit->id])]]);
        try {
            $workflow->purge($visit, $request->user());
        } catch (ValidationException $exception) {
            $this->recordRejected($request, $visit, 'purge', $exception);
            throw $exception;
        }

        return back()->with('status', $displayNumber.' permanently deleted.');
    }

    private function visit(Request $request, string $id, bool $archived): Visit
    {
        $organization = $request->attributes->get('organization');
        $query = $archived ? Visit::onlyTrashed() : Visit::query();
        $visit = $query->forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::withTrashed()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'visit_archive',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $visit;
    }

    private function recordRejected(Request $request, Visit $visit, string $action, ValidationException $exception): void
    {
        app(AuditRecorder::class)->record($request->attributes->get('organization'), $request->user(), "visit.{$action}_rejected", $visit, [
            'ticket_id' => $visit->service_ticket_id,
            'visit_id' => $visit->id,
            'status' => $visit->status,
            'invalid_fields' => array_keys($exception->errors()),
        ]);
    }
}
