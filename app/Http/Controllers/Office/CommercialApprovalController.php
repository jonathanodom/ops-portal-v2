<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialApprovalWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Models\CommercialRevision;
use App\Models\CommercialRevisionApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CommercialApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        abort_unless($request->attributes->get('membership')->hasCapability('quotes.approve'), 403);
        $approvals = CommercialRevisionApproval::query()->where('organization_id', $organization->id)->where('status', 'pending')->with(['revision.document.opportunity.customer', 'requestedBy'])->oldest('requested_at')->paginate(25);

        return view('office.quote-approvals.index', compact('approvals'));
    }

    public function submit(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialApprovalWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $workflow->submit($revision, $request->user());

        return back()->with('status', 'Revision policy evaluated and submitted.');
    }

    public function decide(Request $request, CommercialRevisionApproval $approval, CommercialApprovalWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $approval = CommercialRevisionApproval::query()->where('organization_id', $organization->id)->with('revision.document')->findOrFail($approval->id);
        Gate::authorize('approve', $approval->revision->document);
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:2000']]);
        $workflow->decide($approval, $request->user(), $data['decision'], $data['reason']);

        return redirect()->route('office.quotes.show', [$approval->revision->document, $approval->revision])->with('status', 'Approval decision recorded.');
    }

    private function scoped(Request $request, CommercialDocument $quote, CommercialRevision $revision): array
    {
        $organization = $request->attributes->get('organization');
        $quote = CommercialDocument::query()->forOrganization($organization->id)->where('document_type', 'quote')->findOrFail($quote->id);

        return [$quote, $quote->revisions()->findOrFail($revision->id)];
    }
}
