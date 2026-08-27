<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\OpportunityWorkflow;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CommercialUserPreference;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\OpportunityTask;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OpportunityController extends Controller
{
    public function index(Request $request, CommercialDefaults $defaults): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [Opportunity::class, $organization]);
        $stages = $defaults->ensure($organization);
        $preference = CommercialUserPreference::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $request->user()->id],
            ['opportunity_view' => 'kanban'],
        );
        $requestedView = $request->string('view')->toString();
        if (in_array($requestedView, ['kanban', 'list'], true) && $requestedView !== $preference->opportunity_view) {
            $preference->update(['opportunity_view' => $requestedView]);
        }
        $viewMode = in_array($requestedView, ['kanban', 'list'], true) ? $requestedView : $preference->opportunity_view;
        $query = Opportunity::query()->forOrganization($organization->id)
            ->with(['customer', 'serviceLocation', 'owner', 'stage'])
            ->withMax('activities', 'occurred_at')
            ->addSelect(['latest_audit_at' => AuditEvent::query()->selectRaw('MAX(occurred_at)')
                ->whereColumn('subject_id', 'opportunities.id')
                ->where('subject_type', (new Opportunity)->getMorphClass())]);
        if ($search = trim($request->string('search')->toString())) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($query) use ($escaped): void {
                $query->where('opportunity_number', 'like', "%{$escaped}%")
                    ->orWhere('title', 'like', "%{$escaped}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('display_name', 'like', "%{$escaped}%"))
                    ->orWhereHas('serviceLocation', fn ($location) => $location->where('name', 'like', "%{$escaped}%"));
            });
        }
        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($stage) => $stage->where('semantic_kind', $request->string('stage')));
        }
        if ($request->filled('owner')) {
            $query->where('owner_user_id', $request->integer('owner'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }
        $members = $organization->memberships()->where('status', 'active')->with('user')->get()->pluck('user')->filter()->sortBy('name')->values();
        $opportunities = $viewMode === 'list'
            ? $query->orderByDesc('updated_at')->paginate(30)->withQueryString()
            : $query->orderBy('estimated_close_on')->orderByDesc('updated_at')->limit(250)->get();
        $records = method_exists($opportunities, 'getCollection') ? $opportunities->getCollection() : $opportunities;
        $records->each(function (Opportunity $opportunity): void {
            $opportunity->setAttribute('activities_max_occurred_at', collect([
                $opportunity->activities_max_occurred_at,
                $opportunity->latest_audit_at,
                $opportunity->updated_at,
            ])->filter()->max());
        });

        return view('office.opportunities.index', compact('organization', 'stages', 'members', 'opportunities', 'viewMode'));
    }

    public function create(Request $request, CommercialDefaults $defaults): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [Opportunity::class, $organization]);
        $stages = $defaults->ensure($organization);

        return view('office.opportunities.create', $this->formData($organization, $stages));
    }

    public function store(Request $request, OpportunityWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [Opportunity::class, $organization]);
        $opportunity = $workflow->create($organization, $request->user(), $this->validated($request));

        return redirect()->route('office.opportunities.show', $opportunity)->with('status', 'Opportunity created.');
    }

    public function show(Request $request, Opportunity $opportunity, CommercialDefaults $defaults): View
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('view', $opportunity);
        $organization = $request->attributes->get('organization');
        $stages = $defaults->ensure($organization);
        $opportunity->load(['customer', 'serviceLocation', 'primaryContact', 'owner', 'stage', 'quotes.revisions']);
        $opportunity->setRelation('tasks', $opportunity->tasks()->with('assignee')->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'completed' THEN 1 ELSE 2 END")->orderBy('due_on')->limit(200)->get());
        $opportunity->setRelation('activities', $opportunity->activities()->with('actor')->limit(100)->get());
        $opportunity->setRelation('storedAttachments', $opportunity->storedAttachments()->with('uploader')->limit(100)->get());
        $audits = AuditEvent::query()->where('organization_id', $organization->id)->where('subject_type', $opportunity->getMorphClass())->where('subject_id', $opportunity->id)->with('actor')->latest('occurred_at')->limit(100)->get();
        $members = $organization->memberships()->where('status', 'active')->with('user')->get()->pluck('user')->filter()->sortBy('name')->values();

        return view('office.opportunities.show', compact('opportunity', 'stages', 'audits', 'members'));
    }

    public function update(Request $request, Opportunity $opportunity, OpportunityWorkflow $workflow, AuditRecorder $audit): RedirectResponse
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('update', $opportunity);
        $adminOverride = $request->boolean('confirm_admin_override') && Gate::allows('administer', $opportunity);
        $targetStage = OpportunityStage::query()->where('organization_id', $opportunity->organization_id)->findOrFail($request->integer('stage_id'));
        if (in_array($targetStage->semantic_kind, ['presented', 'won'], true) && ! $adminOverride) {
            $audit->record($opportunity->organization, $request->user(), 'opportunity.stage_change_rejected', $opportunity, [
                'from_stage' => $opportunity->stage->semantic_kind,
                'to_stage' => $targetStage->semantic_kind,
                'reason_code' => 'protected_stage',
            ]);
            throw ValidationException::withMessages(['stage_id' => 'Presented and Won require a Commercial administrator override.']);
        }
        $workflow->update($opportunity, $request->user(), $this->validated($request, $opportunity), $adminOverride);

        return back()->with('status', 'Opportunity updated.');
    }

    public function storeTask(Request $request, Opportunity $opportunity, OpportunityWorkflow $workflow): RedirectResponse
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('update', $opportunity);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'assigned_to_user_id' => ['nullable', 'integer'], 'due_on' => ['nullable', 'date'], 'status' => ['required', Rule::in(OpportunityWorkflow::TASK_STATUSES)]]);
        $workflow->addTask($opportunity, $request->user(), $data);

        return back()->with('status', 'Follow-up task added.');
    }

    public function updateTask(Request $request, Opportunity $opportunity, OpportunityTask $task, OpportunityWorkflow $workflow): RedirectResponse
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('update', $opportunity);
        abort_unless($task->organization_id === $opportunity->organization_id && $task->opportunity_id === $opportunity->id, 404);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'assigned_to_user_id' => ['nullable', 'integer'], 'due_on' => ['nullable', 'date'], 'status' => ['required', Rule::in(OpportunityWorkflow::TASK_STATUSES)]]);
        $workflow->updateTask($opportunity, $task, $request->user(), $data);

        return back()->with('status', 'Follow-up task updated.');
    }

    public function storeActivity(Request $request, Opportunity $opportunity, OpportunityWorkflow $workflow): RedirectResponse
    {
        $opportunity = $this->scoped($request, $opportunity);
        Gate::authorize('update', $opportunity);
        $data = $request->validate(['type' => ['required', Rule::in(OpportunityWorkflow::ACTIVITY_TYPES)], 'body' => ['required', 'string', 'max:10000'], 'occurred_at' => ['nullable', 'date']]);
        $workflow->addActivity($opportunity, $request->user(), $data);

        return back()->with('status', ucfirst($data['type']).' recorded.');
    }

    private function validated(Request $request, ?Opportunity $opportunity = null): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer'], 'service_location_id' => ['nullable', 'integer'], 'primary_contact_id' => ['nullable', 'integer'],
            'owner_user_id' => ['nullable', 'integer'], 'stage_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(OpportunityWorkflow::PRIORITIES)], 'estimated_value_cents' => ['required', 'integer', 'min:0'],
            'estimated_close_on' => ['nullable', 'date'], 'probability_override_bps' => ['nullable', 'integer', 'between:0,10000'],
            'lead_source' => ['nullable', 'string', 'max:100'], 'referral_source' => ['nullable', 'string', 'max:150'],
            'classification' => ['nullable', 'string', 'max:100'], 'next_action' => ['nullable', 'string', 'max:500'],
            'lost_reason' => ['nullable', 'string', 'max:100'], 'lost_note' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function formData($organization, $stages): array
    {
        return [
            'customers' => Customer::query()->where('organization_id', $organization->id)->where('status', 'active')->with(['serviceLocations' => fn ($query) => $query->where('active', true), 'contacts' => fn ($query) => $query->where('active', true)])->orderBy('display_name')->get(),
            'members' => $organization->memberships()->where('status', 'active')->with('user')->get()->pluck('user')->filter()->sortBy('name')->values(),
            'stages' => $stages,
        ];
    }

    private function scoped(Request $request, Opportunity $opportunity): Opportunity
    {
        abort_unless($opportunity->organization_id === $request->attributes->get('organization')->id, 404);

        return $opportunity;
    }
}
