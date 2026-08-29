<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialDefaults;
use App\Http\Controllers\Controller;
use App\Models\CommercialContentBlock;
use App\Models\CommercialTermsSet;
use App\Models\ProposalTemplate;
use App\Models\ProposalTemplateSection;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CommercialLibraryController extends Controller
{
    public function index(Request $request, CommercialDefaults $defaults): View
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $defaults->ensure($organization);
        $organizationId = $organization->id;
        $blocks = CommercialContentBlock::query()->forOrganization($organizationId)->orderBy('name')->get();
        $terms = CommercialTermsSet::query()->forOrganization($organizationId)->orderBy('name')->orderByDesc('version')->get();
        $templates = ProposalTemplate::query()->forOrganization($organizationId)->with('sections')->orderBy('name')->get();

        return view('office.commercial-library.index', compact('blocks', 'terms', 'templates'));
    }

    public function block(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'heading' => ['required', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:20000']]);
        $block = CommercialContentBlock::query()->create($data + ['organization_id' => $organization->id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        $audit->record($organization, $request->user(), 'commercial.content_block_created', $block, ['content_block_id' => $block->id]);

        return back()->with('status', 'Scope block created.');
    }

    public function terms(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:80'], 'name' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:50000']]);
        $version = ((int) CommercialTermsSet::query()->forOrganization($organization->id)->where('code', $data['code'])->max('version')) + 1;
        $terms = CommercialTermsSet::query()->create($data + ['organization_id' => $organization->id, 'version' => $version, 'approved' => true, 'active' => true, 'created_by_id' => $request->user()->id]);
        $audit->record($organization, $request->user(), 'commercial.terms_version_created', $terms, ['terms_set_id' => $terms->id, 'code' => $terms->code, 'version' => $terms->version]);

        return back()->with('status', 'Approved terms version created.');
    }

    public function template(Request $request, ProposalTemplate $template, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $template = ProposalTemplate::query()->forOrganization($organization->id)->findOrFail($template->id);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'acceptance_enabled' => ['nullable', 'boolean'], 'active' => ['nullable', 'boolean']]);
        $template->update(['name' => $data['name'], 'acceptance_enabled' => $request->boolean('acceptance_enabled'), 'active' => $request->boolean('active'), 'updated_by_id' => $request->user()->id]);
        $audit->record($organization, $request->user(), 'proposal.template_updated', $template, ['template_id' => $template->id, 'changed_fields' => ['name', 'acceptance_enabled', 'active']]);

        return back()->with('status', 'Proposal template updated.');
    }

    public function section(Request $request, ProposalTemplate $template, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $template = ProposalTemplate::query()->forOrganization($organization->id)->findOrFail($template->id);
        $data = $request->validate(['section_type' => ['required', Rule::in(['cover', 'scope', 'pricing', 'media', 'terms'])], 'heading' => ['required', 'string', 'max:255'], 'customer_visible' => ['nullable', 'boolean']]);
        $section = $template->sections()->create($data + ['customer_visible' => $request->boolean('customer_visible'), 'sort_order' => ((int) $template->sections()->max('sort_order')) + 10]);
        $audit->record($organization, $request->user(), 'proposal.template_section_created', $template, ['template_id' => $template->id, 'section_id' => $section->id, 'section_type' => $section->section_type]);

        return back()->with('status', 'Template section added.');
    }

    public function updateSection(Request $request, ProposalTemplate $template, ProposalTemplateSection $section, AuditRecorder $audit): RedirectResponse
    {
        $this->authorizeAccess($request);
        $organization = $request->attributes->get('organization');
        $template = ProposalTemplate::query()->forOrganization($organization->id)->findOrFail($template->id);
        $section = $template->sections()->findOrFail($section->id);
        $data = $request->validate(['heading' => ['required', 'string', 'max:255'], 'sort_order' => ['required', 'integer', 'between:0,1000'], 'customer_visible' => ['nullable', 'boolean']]);
        $section->update(['heading' => $data['heading'], 'sort_order' => $data['sort_order'], 'customer_visible' => $request->boolean('customer_visible')]);
        $audit->record($organization, $request->user(), 'proposal.template_section_updated', $template, ['template_id' => $template->id, 'section_id' => $section->id, 'changed_fields' => ['heading', 'sort_order', 'customer_visible']]);

        return back()->with('status', 'Template section updated.');
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->hasCapability('proposal.templates.manage'), 403);
    }
}
