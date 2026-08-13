<?php

namespace App\Http\Controllers\Office;

use App\Domain\CloseoutReviewWorkflow;
use App\Domain\TripChargeRecommender;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Closeout;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CloseoutReviewController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $closeouts = Closeout::query()->where('organization_id', $organization->id)
            ->where('status', 'submitted')->whereDoesntHave('reviews')
            ->with(['visit.serviceTicket.customer', 'visit.serviceLocation', 'submittedBy'])
            ->when($request->filled('outcome'), fn ($query) => $query->where('outcome', $request->string('outcome')))
            ->when($request->filled('priority'), fn ($query) => $query->whereHas('visit.serviceTicket', fn ($ticket) => $ticket->where('priority', $request->string('priority'))))
            ->when($request->filled('technician'), fn ($query) => $query->where('submitted_by_id', $request->integer('technician')))
            ->when($request->filled('customer'), fn ($query) => $query->whereHas('visit.serviceTicket.customer', fn ($customer) => $customer->where('display_name', 'like', '%'.trim((string) $request->query('customer')).'%')))
            ->when($request->string('correction_state')->toString() === 'resubmitted', fn ($query) => $query->where('version', '>', 1))
            ->when($request->string('correction_state')->toString() === 'first_submission', fn ($query) => $query->where('version', 1))
            ->when($request->filled('age'), fn ($query) => $query->where('submitted_at', '<=', now()->subDays($request->integer('age'))))
            ->latest('submitted_at')->paginate(20)->withQueryString();

        return view('office.closeout-reviews.index', [
            'closeouts' => $closeouts,
            'technicians' => Closeout::query()->where('organization_id', $organization->id)->whereNotNull('submitted_by_id')->with('submittedBy')->get()->pluck('submittedBy')->filter()->unique('id')->sortBy('name'),
        ]);
    }

    public function show(Request $request, string $closeout, TripChargeRecommender $tripCharges): View
    {
        $closeout = $this->closeout($request, $closeout);
        $closeout->load('returnVisit.returnOfVisit');
        $visit = $closeout->visit;
        $versions = Closeout::query()->where('organization_id', $closeout->organization_id)->where('visit_id', $visit->id)
            ->with(['submittedBy', 'lastSavedBy', 'timeEntries.user', 'media', 'parts', 'reviews.reviewer', 'reviews.adjustments'])->orderBy('version')->get();
        $visit->load(['serviceTicket.customer', 'serviceTicket.contact', 'serviceTicket.visits.currentCloseout', 'serviceLocation.primaryContact', 'assignments.membership.user', 'timeEntries.user']);
        $completionBlockingVisits = $visit->serviceTicket->visits
            ->where('id', '!=', $visit->id)
            ->whereNotIn('status', ['approved', 'canceled', 'customer_unavailable'])
            ->sortBy('ticket_visit_number')
            ->values();
        $events = AuditEvent::query()->where('organization_id', $closeout->organization_id)
            ->where(function ($query) use ($visit): void {
                $query->where(fn ($q) => $q->where('subject_type', $visit->getMorphClass())->where('subject_id', $visit->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', $visit->serviceTicket->getMorphClass())->where('subject_id', $visit->service_ticket_id));
            })->with('actor')->latest('occurred_at')->limit(50)->get();
        $tripChargeRecommendation = $tripCharges->recommend($visit);

        return view('office.closeout-reviews.show', compact('closeout', 'visit', 'versions', 'events', 'completionBlockingVisits', 'tripChargeRecommendation'));
    }

    public function approve(Request $request, string $closeout, CloseoutReviewWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $closeout = $this->closeout($request, $closeout);
        $data = $request->validate([
            'decision_token' => ['required', 'uuid'],
            'disposition' => ['nullable', Rule::in(['follow_up', 'hold', 'cancel'])],
            'disposition_reason' => ['nullable', 'string', 'max:5000'],
            'time_adjustments' => ['nullable', 'array'],
            'time_adjustments.*.enabled' => ['nullable', 'boolean'],
            'time_adjustments.*.approved_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'time_adjustments.*.excluded' => ['nullable', 'boolean'],
            'time_adjustments.*.reason' => ['nullable', 'string', 'max:2000'],
            'part_adjustments' => ['nullable', 'array'],
            'part_adjustments.*.enabled' => ['nullable', 'boolean'],
            'part_adjustments.*.approved_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'part_adjustments.*.approved_unit' => ['nullable', 'string', 'max:40'],
            'part_adjustments.*.approved_billing_treatment' => ['nullable', Rule::in(array_keys(config('field_execution.billing_treatments')))],
            'part_adjustments.*.excluded' => ['nullable', 'boolean'],
            'part_adjustments.*.reason' => ['nullable', 'string', 'max:2000'],
            'trip_charge_selected' => ['nullable', 'boolean'],
        ]);
        $timeAdjustments = collect($data['time_adjustments'] ?? [])->filter(fn ($values) => (bool) ($values['enabled'] ?? false))->all();
        $partAdjustments = collect($data['part_adjustments'] ?? [])->filter(fn ($values) => (bool) ($values['enabled'] ?? false))->all();
        try {
            $workflow->approve($closeout, $request->user(), $data['decision_token'], $data['disposition'] ?? null, $data['disposition_reason'] ?? null, $timeAdjustments, $partAdjustments, (bool) ($data['trip_charge_selected'] ?? false));
        } catch (ValidationException $exception) {
            $audit->record($request->attributes->get('organization'), $request->user(), 'closeout.review_rejected', $closeout, ['decision' => 'approved', 'invalid_fields' => array_keys($exception->errors())]);
            throw $exception;
        }

        $ticketCompleted = $closeout->visit->serviceTicket()->where('status', 'completed')->exists();

        return redirect()->route('office.closeout-reviews.index')->with(
            'status',
            $ticketCompleted
                ? 'Closeout approved. The Service Ticket is complete and its billing handoff is ready.'
                : 'Closeout approved. The Service Ticket remains open for its remaining visit or disposition.',
        );
    }

    public function returnForCorrection(Request $request, string $closeout, CloseoutReviewWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $closeout = $this->closeout($request, $closeout);
        $data = $request->validate(['decision_token' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:5000']]);
        try {
            $workflow->returnForCorrection($closeout, $request->user(), $data['reason'], $data['decision_token']);
        } catch (ValidationException $exception) {
            $audit->record($request->attributes->get('organization'), $request->user(), 'closeout.review_rejected', $closeout, ['decision' => 'returned', 'invalid_fields' => array_keys($exception->errors())]);
            throw $exception;
        }

        return redirect()->route('office.closeout-reviews.index')->with('status', 'Closeout returned for correction.');
    }

    private function closeout(Request $request, string $id): Closeout
    {
        $organization = $request->attributes->get('organization');
        $closeout = Closeout::query()->where('organization_id', $organization->id)->find($id);
        if (! $closeout) {
            if (Closeout::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, ['record_type' => 'closeout', 'record_id' => (int) $id]);
            }
            abort(404);
        }

        return $closeout;
    }
}
