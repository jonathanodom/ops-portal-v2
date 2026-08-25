<?php

namespace App\Http\Controllers\Office;

use App\Domain\FieldExecution;
use App\Domain\ServiceTicketWorkflow;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use App\Support\ScheduleWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitExecutionController extends Controller
{
    public function transition(Request $request, string $visit, ServiceTicketWorkflow $workflow, FieldExecution $execution): RedirectResponse
    {
        $visit = $this->authorizedVisit($request, $visit);
        $data = $request->validate(['status' => ['required', Rule::in(['en_route', 'on_site'])]]);
        $execution->transition($visit, $request->user(), $data['status'], $workflow);

        return $this->backToVisit($visit, $data['status'] === 'en_route' ? 'Travel started.' : 'Marked on site.');
    }

    public function timer(Request $request, string $visit, FieldExecution $execution): RedirectResponse
    {
        $visit = $this->authorizedVisit($request, $visit);
        $this->assertNotCanceled($visit);
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop'])],
            'category' => ['nullable', Rule::in(['travel', 'on_site', 'other'])],
            'work_item_id' => ['nullable', 'integer'],
        ]);
        $active = VisitTimeEntry::query()->where('active_user_id', $request->user()->id)->first();
        if ($data['action'] === 'stop') {
            if (! $active || $active->visit_id !== $visit->id) {
                throw ValidationException::withMessages(['time' => 'No timer is running for this visit.']);
            }
            $execution->stopTimer($active, $request->user());
        } else {
            if ($active) {
                throw ValidationException::withMessages(['time' => 'Stop your active timer first.']);
            }
            $closeout = $execution->draft($visit, $request->user());
            $execution->startTimer($visit, $closeout, $request->user(), $data['category'] ?? 'other', $this->workItem($visit, $data['work_item_id'] ?? null));
        }

        return $this->backToVisit($visit, 'Time updated.');
    }

    public function storeTime(Request $request, string $visit, FieldExecution $execution, ScheduleWindow $windows): RedirectResponse
    {
        $visit = $this->authorizedVisit($request, $visit);
        $this->assertNotCanceled($visit);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->hasCapability('visits.execute_any'), 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'category' => ['required', Rule::in(['travel', 'on_site', 'other'])],
            'started_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ended_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'correction_reason' => ['required', 'string', 'max:1000'],
            'work_item_id' => ['nullable', 'integer'],
        ]);
        $owner = $this->timeOwner($request, $visit, (int) $data['user_id']);
        $window = $windows->fromLocal($data['started_at'], $data['ended_at'], $visit->timezone);
        $closeout = $execution->draft($visit, $request->user());
        $execution->createManualTime($visit, $closeout, $owner, $request->user(), $data['category'], $window['start'], $window['end'], $data['correction_reason'], $this->workItem($visit, $data['work_item_id'] ?? null));

        return $this->backToVisit($visit, 'Manual time entry added.');
    }

    public function updateTime(Request $request, string $visit, string $entry, FieldExecution $execution, ScheduleWindow $windows): RedirectResponse
    {
        $visit = $this->authorizedVisit($request, $visit);
        $entry = VisitTimeEntry::query()->where('organization_id', $visit->organization_id)->where('visit_id', $visit->id)->findOrFail($entry);
        $membership = $request->attributes->get('membership');
        if ($entry->user_id !== $request->user()->id) {
            abort_unless($membership->hasCapability('visits.execute_any'), 403);
            $this->timeOwner($request, $visit, $entry->user_id);
        }
        $data = $request->validate([
            'started_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ended_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'correction_reason' => ['required', 'string', 'max:1000'],
        ]);
        $window = $windows->fromLocal($data['started_at'], $data['ended_at'], $visit->timezone);
        $execution->correctTime($entry, $request->user(), $window['start'], $window['end'], $data['correction_reason']);

        return $this->backToVisit($visit, 'Time correction saved.');
    }

    private function authorizedVisit(Request $request, string $id): Visit
    {
        $organization = $request->attributes->get('organization');
        $visit = Visit::query()->forOrganization($organization->id)->find($id);
        if (! $visit) {
            if (Visit::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'visit',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }
        Gate::authorize('execute', $visit);

        return $visit;
    }

    private function timeOwner(Request $request, Visit $visit, int $userId): User
    {
        if ($userId === $request->user()->id) {
            return $request->user();
        }
        $membership = OrganizationMembership::query()
            ->where('organization_id', $visit->organization_id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereHas('visitAssignments', fn ($query) => $query->where('visit_id', $visit->id))
            ->with('user')->firstOrFail();

        return $membership->user;
    }

    private function assertNotCanceled(Visit $visit): void
    {
        if ($visit->status === 'canceled') {
            throw ValidationException::withMessages(['visit' => 'Canceled visits are read-only.']);
        }
    }

    private function workItem(Visit $visit, mixed $id): ?ServiceTicketWorkItem
    {
        if (! filled($id)) {
            return null;
        }

        return ServiceTicketWorkItem::query()->where('organization_id', $visit->organization_id)
            ->where('service_ticket_id', $visit->service_ticket_id)->findOrFail((int) $id);
    }

    private function backToVisit(Visit $visit, string $status): RedirectResponse
    {
        return redirect()->to(route('office.service-tickets.show', $visit->service_ticket_id).'?execution_visit='.$visit->id)
            ->with('status', $status);
    }
}
