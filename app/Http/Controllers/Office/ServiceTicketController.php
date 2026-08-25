<?php

namespace App\Http\Controllers\Office;

use App\Domain\AdminManualCloseoutWorkflow;
use App\Domain\ServiceTicketCreationValidator;
use App\Domain\ServiceTicketCreator;
use App\Domain\ServiceTicketWorkflow;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\ServiceTicketNote;
use App\Models\Visit;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceTicketController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->organization($request);
        Gate::authorize('viewAny', [ServiceTicket::class, $organization]);
        $search = trim((string) $request->query('search', ''));

        $tickets = ServiceTicket::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceLocation'])
            ->withCount('visits')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where('ticket_number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'like', $like))
                    ->orWhereHas('serviceLocation', fn ($location) => $location->where('name', 'like', $like));
            }))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('source'), fn ($query) => $query->where('source', $request->string('source')))
            ->when($request->filled('purpose'), fn ($query) => $query->where('purpose', $request->string('purpose')))
            ->when($request->filled('billing_disposition'), fn ($query) => $query->where('billing_disposition', $request->string('billing_disposition')))
            ->when($request->filled('assignee'), fn ($query) => $query->whereHas(
                'visits.assignments',
                fn ($assignment) => $assignment->where('organization_membership_id', $request->integer('assignee'))
            ))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('office.service-tickets.index', [
            'tickets' => $tickets,
            'memberships' => $this->fieldMemberships($organization),
            ...$this->options(),
        ]);
    }

    public function create(Request $request): View
    {
        $organization = $this->organization($request);
        Gate::authorize('create', [ServiceTicket::class, $organization]);
        $selectedCustomer = null;
        if ($request->old('customer_id')) {
            $selectedCustomer = Customer::query()->forOrganization($organization->id)
                ->where('status', 'active')
                ->with([
                    'serviceLocations' => fn ($query) => $query->where('active', true)->orderByDesc('is_primary')->orderBy('name'),
                    'contacts' => fn ($query) => $query->where('active', true)->orderByDesc('is_preferred')->orderBy('name'),
                ])
                ->find($request->old('customer_id'));
        }

        return view('office.service-tickets.create', [
            'customerPicker' => true,
            'selectedCustomer' => $selectedCustomer,
            'canQuickAddCustomer' => Gate::allows('create', [Customer::class, $organization]),
            'customerTypes' => config('customers.types'),
            'states' => config('customers.states'),
            'defaultTimezone' => $organization->timezone,
            'memberships' => $this->fieldMemberships($organization),
            ...$this->options(),
        ]);
    }

    public function store(
        Request $request,
        ServiceTicketCreator $creator,
        ServiceTicketCreationValidator $validator,
    ): RedirectResponse {
        $organization = $this->organization($request);
        Gate::authorize('create', [ServiceTicket::class, $organization]);
        $data = $validator->validate($request, $organization);
        $ticket = $creator->create($organization, $request->user(), $data, $request->boolean('create_visit'), $request->boolean('confirm_conflicts'));

        return redirect()->route('office.service-tickets.show', $ticket)->with('status', 'Service ticket created.');
    }

    public function show(Request $request, string $serviceTicket, AdminManualCloseoutWorkflow $manualCloseout): View
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('view', $ticket);
        $ticket->load([
            'customer.contacts' => fn ($query) => $query->where('active', true)->orderByDesc('is_preferred'),
            'serviceLocation.primaryContact',
            'contact',
            'invoices' => fn ($query) => $query->latest('generation')->withExists('acknowledgments'),
            'files' => fn ($query) => $query->with('uploader')->latest(),
            'notes.author',
            'reopens.reopenedBy',
            'originatingWorkItem.serviceTicket',
            'workItems' => fn ($query) => $query->with(['discoveredVisit.returnOfVisit', 'visits.returnOfVisit', 'followUpServiceTicket'])->orderBy('id'),
            'visits' => fn ($query) => $query->with(['returnOfVisit', 'assignments.membership.user', 'timeEntries.user', 'timeEntries.workItem', 'timeEntries.allocationSets.allocatedBy', 'timeEntries.allocationSets.allocations.workItem', 'timeEntries.closeout.reviews', 'timeEntries.corrections.correctedBy', 'currentCloseout.lastSavedBy', 'currentCloseout.media', 'currentCloseout.parts', 'currentCloseout.reviews.reviewer'])->orderBy('scheduled_start_at')->orderBy('ticket_visit_number'),
        ]);
        $events = AuditEvent::query()->where('organization_id', $ticket->organization_id)
            ->with('actor')
            ->where(fn ($query) => $query
                ->where(fn ($inner) => $inner->where('subject_type', $ticket->getMorphClass())->where('subject_id', $ticket->id))
                ->orWhere(fn ($inner) => $inner->where('subject_type', (new Visit)->getMorphClass())->whereIn('subject_id', $ticket->visits->pluck('id')))
                ->orWhere(fn ($inner) => $inner->where('subject_type', (new ServiceTicketFile)->getMorphClass())->whereIn('subject_id', $ticket->files->pluck('id'))))
            ->latest('occurred_at')->limit(100)->get();
        $membership = $request->attributes->get('membership');
        $canCorrectSubmittedTime = $membership->roles->contains('key', 'super_admin')
            && $membership->hasCapability('visit_time.correct_submitted');
        $executableVisitIds = $ticket->visits->filter(function (Visit $visit) use ($membership): bool {
            if ($membership->hasCapability('visits.execute_any')) {
                return true;
            }

            return $membership->hasCapability('visits.execute_assigned')
                && $visit->assignments->contains('organization_membership_id', $membership->id);
        })->pluck('id')->all();
        $archivableVisitIds = $membership->hasCapability('visits.archive.manage')
            ? $ticket->visits->filter(function (Visit $visit) use ($ticket): bool {
                return in_array($visit->status, ['planned', 'scheduled', 'assigned', 'canceled'], true)
                    && $visit->timeEntries->whereNull('ended_at')->isEmpty()
                    && $visit->currentCloseout?->status !== 'submitted'
                    && ! $ticket->visits->contains('return_of_visit_id', $visit->id);
            })->pluck('id')->all()
            : [];
        $manualCloseoutVisitIds = $membership->hasCapability('closeouts.manual_complete')
            ? $ticket->visits->filter(fn (Visit $visit): bool => $manualCloseout->canStart($visit))->pluck('id')->all()
            : [];

        return view('office.service-tickets.show', compact('ticket', 'events', 'executableVisitIds', 'archivableVisitIds', 'manualCloseoutVisitIds', 'canCorrectSubmittedTime') + $this->options());
    }

    public function edit(Request $request, string $serviceTicket): View
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $ticket->load(['customer.contacts' => fn ($query) => $query->where('active', true), 'customer.serviceLocations' => fn ($query) => $query->where('active', true)]);

        return view('office.service-tickets.edit', ['ticket' => $ticket, ...$this->options()]);
    }

    public function update(Request $request, string $serviceTicket, AuditRecorder $audit): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $this->validated($request, $this->organization($request), $ticket);
        if ($ticket->visits()->exists()
            && ((int) $data['customer_id'] !== $ticket->customer_id || (int) $data['service_location_id'] !== $ticket->service_location_id)) {
            throw ValidationException::withMessages([
                'service_location_id' => 'Customer and location cannot change after a visit has been created.',
            ]);
        }
        $before = $ticket->getAttributes();
        $ticket->update([
            'customer_id' => $data['customer_id'],
            'service_location_id' => $data['service_location_id'],
            'contact_id' => $data['contact_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'customer_visible_summary' => $data['customer_visible_summary'] ?? null,
            'priority' => $data['priority'],
            'source' => $data['source'],
            'purpose' => $data['purpose'],
            'billing_disposition' => $data['billing_disposition'],
            'updated_by_id' => $request->user()->id,
        ]);
        $changed = array_values(array_diff(array_keys(array_diff_assoc($ticket->getAttributes(), $before)), ['updated_at']));
        $audit->record($this->organization($request), $request->user(), 'service_ticket.updated', $ticket, ['changed_fields' => $changed]);

        return redirect()->route('office.service-tickets.show', $ticket)->with('status', 'Service ticket updated.');
    }

    public function addNote(Request $request, string $serviceTicket, AuditRecorder $audit): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $note = ServiceTicketNote::query()->create([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
            'created_at' => now(),
        ]);
        $audit->record($this->organization($request), $request->user(), 'service_ticket.note_added', $note, ['ticket_id' => $ticket->id]);

        return back()->with('status', 'Internal note added.');
    }

    public function transition(Request $request, string $serviceTicket, ServiceTicketWorkflow $workflow): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'on_hold', 'canceled'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'confirm_stop_active_timers' => ['sometimes', 'accepted'],
        ]);
        $workflow->changeTicketStatus($ticket, $data['status'], $request->user(), $data['reason'] ?? null, $request->boolean('confirm_stop_active_timers'));

        return back()->with('status', 'Service ticket status updated.');
    }

    public function reopen(Request $request, string $serviceTicket, ServiceTicketWorkflow $workflow): RedirectResponse
    {
        $ticket = $this->ticket($request, $serviceTicket);
        Gate::authorize('update', $ticket);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $workflow->reopenForCallback($ticket, $request->user(), $data['reason']);

        return back()->with('status', 'Service Ticket reopened for callback work. Add a new Visit when ready.');
    }

    private function validated(Request $request, Organization $organization, ?ServiceTicket $ticket = null): array
    {
        $rules = [
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('organization_id', $organization->id)->where('status', 'active')],
            'service_location_id' => ['required', Rule::exists('service_locations', 'id')->where('organization_id', $organization->id)->where('active', true)],
            'contact_id' => ['nullable', Rule::exists('contacts', 'id')->where('organization_id', $organization->id)->where('active', true)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'customer_visible_summary' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(array_keys(config('service_tickets.priorities')))],
            'source' => ['required', Rule::in(array_keys(config('service_tickets.sources')))],
            'purpose' => ['sometimes', Rule::in(array_keys(config('service_tickets.purposes')))],
            'billing_disposition' => ['sometimes', Rule::in(array_keys(config('service_tickets.billing_dispositions')))],
        ];
        if (! $ticket) {
            $rules += [
                'create_visit' => ['nullable', 'boolean'],
                'scheduled_start' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'scheduled_end' => ['nullable', 'date_format:Y-m-d\TH:i'],
                'assignees' => ['nullable', 'array'],
                'assignees.*' => ['integer'],
                'lead_membership_id' => ['nullable', 'integer'],
            ];
        }
        $data = $request->validate($rules);
        $location = ServiceLocation::query()->where('organization_id', $organization->id)->findOrFail($data['service_location_id']);
        if ((int) $location->customer_id !== (int) $data['customer_id']) {
            throw ValidationException::withMessages(['service_location_id' => 'The location must belong to the selected customer.']);
        }
        if (filled($data['contact_id'] ?? null) && ! Contact::query()->whereKey($data['contact_id'])->where('customer_id', $data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'The contact must belong to the selected customer.']);
        }

        return $data + [
            'purpose' => $data['purpose'] ?? $ticket?->purpose ?? 'service_call',
            'billing_disposition' => $data['billing_disposition'] ?? $ticket?->billing_disposition ?? 'billable',
        ];
    }

    private function ticket(Request $request, string $id): ServiceTicket
    {
        $organization = $this->organization($request);
        $ticket = ServiceTicket::query()->forOrganization($organization->id)->withExists('billingHandoff')->find($id);
        if (! $ticket) {
            if (ServiceTicket::query()->whereKey($id)->exists()) {
                app(AuditRecorder::class)->record($organization, $request->user(), 'security.cross_organization_record_denied', $organization, [
                    'record_type' => 'service_ticket',
                    'record_id' => (int) $id,
                ]);
            }
            abort(404);
        }

        return $ticket;
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function fieldMemberships(Organization $organization): Collection
    {
        return $organization->memberships()->with(['user', 'roles.capabilities', 'capabilityOverrides'])
            ->where('status', 'active')->get()
            ->filter(fn ($membership) => $membership->hasCapability('experience.field.access'))
            ->sortBy(fn ($membership) => $membership->user->name)->values();
    }

    private function options(): array
    {
        return [
            'priorities' => config('service_tickets.priorities'),
            'sources' => config('service_tickets.sources'),
            'statuses' => config('service_tickets.statuses'),
            'purposes' => config('service_tickets.purposes'),
            'billingDispositions' => config('service_tickets.billing_dispositions'),
        ];
    }
}
