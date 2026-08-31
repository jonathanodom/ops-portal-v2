<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\LeadIntakeConverter;
use App\Domain\Commercial\LeadIntakeCreator;
use App\Domain\Commercial\LeadIntakeDisposition;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManualLeadIntakeRequest;
use App\Models\CommercialLeadIntake;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class LeadIntakeController extends Controller
{
    private const FILTERS = [
        'open' => 'received',
        'converted' => 'converted',
        'archived' => 'archived',
        'spam' => 'spam',
        'all' => null,
    ];

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CommercialLeadIntake::class, $organization]);

        return view('office.leads.create', [
            'serviceInterests' => config('lead-intake.service_interests', []),
        ]);
    }

    public function store(
        ManualLeadIntakeRequest $request,
        LeadIntakeCreator $creator,
        AuditRecorder $audit,
    ): RedirectResponse {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CommercialLeadIntake::class, $organization]);

        $lead = DB::transaction(function () use ($request, $creator, $audit, $organization): CommercialLeadIntake {
            $lead = $creator->create($organization, $request->normalizedLeadData());
            $audit->record($organization, $request->user(), 'commercial_lead_intake.created_manual', $lead, [
                'lead_intake_id' => $lead->id,
                'source' => 'manual',
            ]);

            return $lead;
        });

        return redirect()->route('office.leads.show', $lead)->with('status', 'Lead created.');
    }

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CommercialLeadIntake::class, $organization]);

        $filter = $request->string('filter')->toString();
        if (! array_key_exists($filter, self::FILTERS)) {
            $filter = 'open';
        }

        $query = CommercialLeadIntake::query()->forOrganization($organization->id);
        if (self::FILTERS[$filter] !== null) {
            $query->where('status', self::FILTERS[$filter]);
        }
        if ($search = trim($request->string('search')->toString())) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($query) use ($escaped): void {
                $query->where('first_name', 'like', "%{$escaped}%")
                    ->orWhere('last_name', 'like', "%{$escaped}%")
                    ->orWhere('company', 'like', "%{$escaped}%")
                    ->orWhere('email', 'like', "%{$escaped}%")
                    ->orWhere('phone', 'like', "%{$escaped}%")
                    ->orWhere('service_interest', 'like', "%{$escaped}%");
            });
        }

        $leads = $query->latest('received_at')->latest('id')->paginate(30)->withQueryString();

        return view('office.leads.index', compact('leads', 'filter'));
    }

    public function show(Request $request, CommercialLeadIntake $lead): View
    {
        $lead = $this->scoped($request, $lead);
        Gate::authorize('view', $lead);
        $lead->load('opportunity');

        return view('office.leads.show', compact('lead'));
    }

    public function convert(Request $request, CommercialLeadIntake $lead, LeadIntakeConverter $converter): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $lead = $this->scoped($request, $lead);
        Gate::authorize('convert', $lead);
        $opportunity = $converter->convert($organization, $lead, $request->user());

        return redirect()->route('office.opportunities.show', $opportunity)->with('status', 'Lead converted to Opportunity.');
    }

    public function spam(Request $request, CommercialLeadIntake $lead, LeadIntakeDisposition $disposition): RedirectResponse
    {
        $lead = $this->scoped($request, $lead);
        Gate::authorize('manage', $lead);
        $disposition->markSpam($request->attributes->get('organization'), $lead, $request->user());

        return back()->with('status', 'Lead marked as spam.');
    }

    public function archive(Request $request, CommercialLeadIntake $lead, LeadIntakeDisposition $disposition): RedirectResponse
    {
        $lead = $this->scoped($request, $lead);
        Gate::authorize('manage', $lead);
        $disposition->archive($request->attributes->get('organization'), $lead, $request->user());

        return back()->with('status', 'Lead archived.');
    }

    public function reopen(Request $request, CommercialLeadIntake $lead, LeadIntakeDisposition $disposition): RedirectResponse
    {
        $lead = $this->scoped($request, $lead);
        Gate::authorize('manage', $lead);
        $disposition->reopen($request->attributes->get('organization'), $lead, $request->user());

        return back()->with('status', 'Lead reopened.');
    }

    private function scoped(Request $request, CommercialLeadIntake $lead): CommercialLeadIntake
    {
        abort_unless($lead->organization_id === $request->attributes->get('organization')->id, 404);

        return $lead;
    }
}
