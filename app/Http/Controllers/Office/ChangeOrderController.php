<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\ChangeOrderApplicationWorkflow;
use App\Domain\Commercial\ChangeOrderWorkflow;
use App\Domain\Commercial\ProjectAllowanceWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectCommercialScope;
use App\Models\ProposalAcceptance;
use App\Models\ProposalPublication;
use App\Support\FixedPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ChangeOrderController extends Controller
{
    public function store(Request $request, Project $project, ChangeOrderWorkflow $workflow): RedirectResponse
    {
        $project = Project::query()->forOrganization($request->attributes->get('organization')->id)->findOrFail($project->id);
        $this->requireCapabilities($request, ['change_orders.manage', 'quotes.manage', 'projects.manage']);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $document = $workflow->create($project, $request->user(), $data['title']);

        return redirect()->route('office.quotes.show', [$document, $document->revisions()->firstOrFail()])->with('status', 'Change Order Draft created.');
    }

    public function apply(Request $request, ProposalPublication $publication, ChangeOrderApplicationWorkflow $workflow): RedirectResponse
    {
        $publication = ProposalPublication::query()->forOrganization($request->attributes->get('organization')->id)->with('revision.document')->findOrFail($publication->id);
        abort_unless($publication->revision->document->document_type === 'change_order', 404);
        $this->requireCapabilities($request, ['change_orders.manage', 'projects.admin']);
        $request->validate(['confirm_apply' => ['accepted']]);
        $acceptance = ProposalAcceptance::query()->where('organization_id', $publication->organization_id)->where('proposal_publication_id', $publication->id)->firstOrFail();
        $scope = $workflow->apply($acceptance, $request->user());

        return redirect()->route('office.projects.show', $scope->project_id)->with('status', 'Accepted Change Order reviewed and applied.');
    }

    public function resolveAllowance(Request $request, Project $project, ProjectCommercialScope $scope, ProjectAllowanceWorkflow $workflow): RedirectResponse
    {
        $project = Project::query()->forOrganization($request->attributes->get('organization')->id)->findOrFail($project->id);
        abort_unless((int) $scope->organization_id === (int) $project->organization_id && (int) $scope->project_id === (int) $project->id, 404);
        $this->requireCapabilities($request, ['change_orders.manage', 'projects.manage']);
        $data = $request->validate(['source_revision_line_id' => ['required', 'integer'], 'resolved_amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/']]);
        $resolution = $workflow->resolve($project, $scope, (int) $data['source_revision_line_id'], FixedPoint::dollarsToCents($data['resolved_amount']), $request->user());
        $message = $resolution->variance_cents === 0 ? 'Allowance resolved within its accepted amount.' : 'Allowance variance recorded. Create and accept a Change Order before changing the contract total.';

        return back()->with('status', $message);
    }

    /** @param array<int,string> $capabilities */
    private function requireCapabilities(Request $request, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            abort_unless($request->attributes->get('membership')->hasCapability($capability), 403);
        }
    }
}
