<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\ProjectConversionWorkflow;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectConversionTemplate;
use App\Models\ProposalAcceptance;
use App\Models\ProposalPublication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ProjectConversionController extends Controller
{
    public function create(Request $request, ProposalPublication $publication, CommercialDefaults $defaults): View|RedirectResponse
    {
        [$publication, $acceptance] = $this->context($request, $publication);
        if ($acceptance->projectScope) {
            return redirect()->route('office.projects.show', $acceptance->projectScope->project_id)->with('status', 'This accepted scope is already converted.');
        }
        $defaults->ensure($request->attributes->get('organization'));
        $acceptance->load(['selections', 'milestones.invoice']);

        return view('office.proposal-publications.convert', [
            'publication' => $publication,
            'acceptance' => $acceptance,
            'templates' => ProjectConversionTemplate::query()->where('organization_id', $publication->organization_id)->where('active', true)->with(['workstreams', 'milestones'])->orderBy('name')->get(),
            'projects' => Project::query()->where('organization_id', $publication->organization_id)->where('customer_id', $publication->revision->document->opportunity->customer_id)->whereNotIn('status', ['completed', 'canceled'])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ProposalPublication $publication, ProjectConversionWorkflow $workflow): RedirectResponse
    {
        [$publication, $acceptance] = $this->context($request, $publication);
        $data = $request->validate([
            'project_mode' => ['required', Rule::in(['new', 'existing'])],
            'project_id' => ['nullable', 'integer'],
            'project_name' => ['required_if:project_mode,new', 'nullable', 'string', 'max:255'],
            'project_type' => ['required_if:project_mode,new', 'nullable', Rule::in(['installation_project', 'ongoing_support', 'consulting_engineering'])],
            'project_conversion_template_id' => ['nullable', 'integer'],
            'confirm_location_mismatch' => ['nullable', 'boolean'],
            'ticket_line_ids' => ['nullable', 'array'],
            'ticket_line_ids.*' => ['integer'],
            'confirm_conversion' => ['accepted'],
        ]);
        if (($data['project_mode'] === 'existing') && empty($data['project_id'])) {
            throw ValidationException::withMessages(['project_id' => 'Choose an existing Project.']);
        }
        if ($request->collect('ticket_line_ids')->isNotEmpty()) {
            $this->requireCapabilities($request, ['projects.admin', 'dispatch.manage']);
        }
        $scope = $workflow->convert($acceptance, $request->user(), $data + ['confirm_location_mismatch' => $request->boolean('confirm_location_mismatch')]);

        return redirect()->route('office.projects.show', $scope->project_id)->with('status', 'Accepted commercial scope converted to Project planning.');
    }

    /** @return array{ProposalPublication,ProposalAcceptance} */
    private function context(Request $request, ProposalPublication $publication): array
    {
        abort_unless((int) $publication->organization_id === (int) $request->attributes->get('organization')->id, 404);
        $this->requireCapabilities($request, ['commercial.convert', 'projects.manage']);
        $publication->load('revision.document.opportunity');
        abort_unless($publication->revision->document->document_type === 'quote', 404);
        $acceptance = ProposalAcceptance::query()->where('organization_id', $publication->organization_id)->where('proposal_publication_id', $publication->id)->with('projectScope')->firstOrFail();

        return [$publication, $acceptance];
    }

    /** @param array<int,string> $capabilities */
    private function requireCapabilities(Request $request, array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            abort_unless($request->attributes->get('membership')->hasCapability($capability), 403);
        }
    }
}
