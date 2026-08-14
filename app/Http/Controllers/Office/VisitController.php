<?php

namespace App\Http\Controllers\Office;

use App\Domain\ServiceTicketWorkflow;
use App\Domain\VisitCreator;
use App\Domain\VisitScheduler;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ServiceTicket;
use App\Models\Visit;
use App\Support\AuditRecorder;
use App\Support\ScheduleWindow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VisitController extends Controller
{
    public function create(Request $request, string $serviceTicket): View
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $this->assertTicketAcceptsVisits($ticket);

        return view('office.visits.create', [
            'ticket' => $ticket->load('serviceLocation'),
            'memberships' => $this->fieldMemberships($this->organization($request)),
        ]);
    }

    public function store(
        Request $request,
        string $serviceTicket,
        ScheduleWindow $windows,
        VisitScheduler $scheduler,
        AuditRecorder $audit,
        VisitCreator $visitCreator,
    ): RedirectResponse {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $this->assertTicketAcceptsVisits($ticket);
        $data = $this->validated($request);
        $visit = DB::transaction(function () use ($request, $ticket, $data, $windows, $scheduler, $audit, $visitCreator): Visit {
            $visit = $visitCreator->create($ticket, [
                'service_location_id' => $ticket->service_location_id,
                'status' => 'planned',
                'timezone' => $ticket->serviceLocation->timezone,
                'created_by_id' => $request->user()->id,
                'updated_by_id' => $request->user()->id,
            ]);
            $scheduler->save(
                $visit,
                $windows->fromLocal($data['scheduled_start'] ?? null, $data['scheduled_end'] ?? null, $visit->timezone),
                $data['assignees'] ?? [],
                isset($data['lead_membership_id']) ? (int) $data['lead_membership_id'] : null,
                $request->user(),
                $request->boolean('confirm_conflicts'),
            );
            $audit->record($this->organization($request), $request->user(), 'visit.created', $visit, ['ticket_id' => $ticket->id]);

            return $visit;
        });

        return redirect()->route('office.service-tickets.show', $ticket)->with('status', 'Visit created.');
    }

    public function edit(Request $request, string $visit): View
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('dispatch', [Visit::class, $this->organization($request)]);
        $visit->load(['serviceTicket', 'assignments']);
        $this->assertVisitCanBeScheduled($visit);

        return view('office.visits.edit', [
            'visit' => $visit,
            'memberships' => $this->fieldMemberships($this->organization($request)),
        ]);
    }

    public function update(
        Request $request,
        string $visit,
        ScheduleWindow $windows,
        VisitScheduler $scheduler,
    ): RedirectResponse {
        $visit = $this->visit($request, $visit);
        Gate::authorize('dispatch', [Visit::class, $this->organization($request)]);
        $visit->loadMissing('serviceTicket');
        $this->assertVisitCanBeScheduled($visit);
        $data = $this->validated($request);
        $scheduler->save(
            $visit,
            $windows->fromLocal($data['scheduled_start'] ?? null, $data['scheduled_end'] ?? null, $visit->timezone),
            $data['assignees'] ?? [],
            isset($data['lead_membership_id']) ? (int) $data['lead_membership_id'] : null,
            $request->user(),
            $request->boolean('confirm_conflicts'),
        );

        return redirect()->route('office.service-tickets.show', $visit->serviceTicket)->with('status', 'Visit schedule updated.');
    }

    public function cancel(Request $request, string $visit, ServiceTicketWorkflow $workflow): RedirectResponse
    {
        $visit = $this->visit($request, $visit);
        Gate::authorize('dispatch', [Visit::class, $this->organization($request)]);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'confirm_stop_active_timers' => ['sometimes', 'accepted'],
        ]);
        $workflow->cancelVisit($visit, $request->user(), $data['reason'], $request->boolean('confirm_stop_active_timers'));

        return back()->with('status', 'Visit canceled.');
    }

    public function createReturn(Request $request, string $visit, AuditRecorder $audit, VisitCreator $visitCreator): RedirectResponse
    {
        $source = $this->visit($request, $visit);
        Gate::authorize('dispatch', [Visit::class, $this->organization($request)]);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        if ($source->status !== 'on_site') {
            throw ValidationException::withMessages(['reason' => 'A return visit can only be created after the source visit is on site.']);
        }
        $return = $visitCreator->create($source->serviceTicket, [
            'service_location_id' => $source->service_location_id,
            'return_of_visit_id' => $source->id,
            'status' => 'planned',
            'timezone' => $source->timezone,
            'return_reason' => $data['reason'],
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);
        $audit->record($this->organization($request), $request->user(), 'visit.return_created', $return, [
            'ticket_id' => $source->service_ticket_id,
            'source_visit_id' => $source->id,
        ]);

        return redirect()->route('office.visits.edit', $return)->with('status', 'Return visit created. Add its schedule and crew.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'scheduled_start' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'scheduled_end' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => ['integer'],
            'lead_membership_id' => ['nullable', 'integer'],
            'confirm_conflicts' => ['nullable', 'boolean'],
        ]);
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        return ServiceTicket::query()->forOrganization($this->organization($request)->id)->findOrFail($id);
    }

    private function visit(Request $request, string $id): Visit
    {
        $organization = $this->organization($request);
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

    private function fieldMemberships(Organization $organization): Collection
    {
        return $organization->memberships()->with(['user', 'roles.capabilities', 'capabilityOverrides'])
            ->where('status', 'active')->get()
            ->filter(fn ($membership) => $membership->hasCapability('experience.field.access'))
            ->sortBy(fn ($membership) => $membership->user->name)->values();
    }

    private function assertTicketAcceptsVisits(ServiceTicket $ticket): void
    {
        abort_unless(
            in_array($ticket->status, ['open', 'on_hold'], true),
            422,
            'Only open or on-hold Service Tickets can receive Visits. Reopen this ticket first.',
        );
    }

    private function assertVisitCanBeScheduled(Visit $visit): void
    {
        $this->assertTicketAcceptsVisits($visit->serviceTicket);
        abort_unless(
            in_array($visit->status, ['planned', 'scheduled', 'assigned'], true),
            422,
            'Only pre-execution Visits can be scheduled or reassigned.',
        );
    }
}
