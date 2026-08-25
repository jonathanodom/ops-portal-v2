<?php

namespace App\Http\Controllers\Field;

use App\Domain\ServiceTicketWorkItemWorkflow;
use App\Http\Controllers\Controller;
use App\Models\ServiceTicketWorkItem;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class VisitWorkItemController extends Controller
{
    public function store(Request $request, string $visit, ServiceTicketWorkItemWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('execute', $visit);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'], 'detail' => ['nullable', 'string', 'max:10000'],
            'work_note' => ['nullable', 'string', 'max:10000'],
        ]);
        $workflow->createFromField($visit, $request->user(), $data);

        return back()->with('status', 'Additional Work Item recorded.');
    }

    public function update(Request $request, string $visit, string $workItem, ServiceTicketWorkItemWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('execute', $visit);
        $item = ServiceTicketWorkItem::query()->forOrganization($visit->organization_id)
            ->where('service_ticket_id', $visit->service_ticket_id)->findOrFail($workItem);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'completed', 'needs_follow_up'])],
            'work_note' => ['nullable', 'string', 'max:10000'],
        ]);
        $workflow->updateFromField($item, $visit, $request->user(), $data);

        return back()->with('status', 'Work Item updated.');
    }

    private function visit(Request $request, string $id): Visit
    {
        $organization = $request->attributes->get('organization');
        $visit = Visit::query()->forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'visit', 'record_id' => (int) $id]);
            }
            abort(404);
        }

        return $visit;
    }
}
