<?php

namespace App\Http\Controllers\Field;

use App\Domain\FieldExecution;
use App\Domain\ServiceTicketWorkflow;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TodayController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $membership = $request->attributes->get('membership');
        $today = CarbonImmutable::now($organization->timezone)->startOfDay();
        $query = $this->authorizedQuery($membership)->with(['serviceTicket.customer', 'serviceLocation', 'assignments.membership.user']);

        return view('field.today', [
            'today' => (clone $query)->whereBetween('scheduled_start_at', [$today->utc(), $today->endOfDay()->utc()])->orderBy('scheduled_start_at')->get(),
            'upcoming' => (clone $query)->where('scheduled_start_at', '>=', $today->addDay()->utc())
                ->where('scheduled_start_at', '<', $today->addDays(8)->utc())->orderBy('scheduled_start_at')->get(),
        ]);
    }

    public function show(Request $request, string $visit): View
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('view', $visit);
        $visit->load([
            'serviceTicket.customer',
            'serviceTicket.contact',
            'serviceTicket.visits' => fn ($query) => $query->select(['id', 'service_ticket_id', 'status', 'scheduled_start_at', 'timezone'])->orderBy('id'),
            'serviceLocation.primaryContact',
            'assignments.membership.user',
            'currentCloseout.lastSavedBy', 'currentCloseout.timeEntries.user', 'currentCloseout.media', 'currentCloseout.parts',
        ]);

        return view('field.visits.show', compact('visit'));
    }

    public function transition(Request $request, string $visit, ServiceTicketWorkflow $workflow, FieldExecution $execution): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('execute', $visit);
        $data = $request->validate(['status' => ['required', 'in:en_route,on_site']]);
        $active = VisitTimeEntry::query()->where('active_user_id', $request->user()->id)->first();
        if ($active && $active->visit_id !== $visit->id) {
            return back()->withErrors(['time' => 'Stop the timer on your other visit before changing this visit status.']);
        }
        $workflow->executeVisit($visit, $data['status'], $request->user());
        $visit->refresh();
        $closeout = $execution->draft($visit, $request->user());
        if ($active) {
            $execution->stopTimer($active, $request->user());
        }
        $execution->startTimer($visit, $closeout, $request->user(), $data['status'] === 'en_route' ? 'travel' : 'on_site');

        return back()->with('status', $data['status'] === 'en_route' ? 'Travel started.' : 'Marked on site.');
    }

    private function authorizedQuery(OrganizationMembership $membership)
    {
        return Visit::query()->forOrganization($membership->organization_id)
            ->where('status', '!=', 'canceled')
            ->when(! $membership->hasCapability('visits.inspect_all'), function ($query) use ($membership): void {
                if ($membership->hasCapability('visits.execute_any')) {
                    return;
                }
                $query->whereHas('assignments', fn ($assignment) => $assignment->where('organization_membership_id', $membership->id));
            });
    }

    private function visit(Request $request, string $id): Visit
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

        return $visit;
    }
}
