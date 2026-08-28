<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\QuoteWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use App\Models\CatalogLaborRole;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CommercialDocument;
use App\Models\CommercialRevision;
use App\Models\CommercialRevisionLine;
use App\Models\CommercialRevisionLineComponent;
use App\Models\Opportunity;
use App\Models\UnitOfMeasure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class QuoteController extends Controller
{
    public function store(Request $request, Opportunity $opportunity, QuoteWorkflow $workflow): RedirectResponse
    {
        $opportunity = $this->opportunity($request, $opportunity);
        Gate::authorize('create', [CommercialDocument::class, $opportunity]);
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $document = $workflow->create($opportunity, $request->user(), $data['title']);

        return redirect()->route('office.quotes.show', [$document, $document->revisions()->firstOrFail()])->with('status', 'Quote created.');
    }

    public function show(Request $request, CommercialDocument $quote, CommercialRevision $revision): View
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('view', $quote);
        $revision->load(['document.opportunity.customer', 'document.opportunity.serviceLocation', 'locations.parent', 'systems', 'phases', 'sections', 'lines.components', 'lines.location', 'lines.system', 'lines.phase', 'lines.category', 'paymentMilestones']);
        $organizationId = $quote->organization_id;
        $services = CatalogService::query()->forOrganization($organizationId)->where('active', true)->with(['variants' => fn ($q) => $q->where('active', true), 'salesUom'])->orderBy('name')->get();
        $products = CatalogProduct::query()->forOrganization($organizationId)->where('active', true)->with('defaultSalesUom')->orderBy('name')->get();
        $packages = CatalogPackage::query()->forOrganization($organizationId)->where('active', true)->with('salesUom')->orderBy('name')->get();
        $categories = CatalogCategory::query()->forOrganization($organizationId)->where('active', true)->orderBy('name')->get();
        $units = UnitOfMeasure::query()->forOrganization($organizationId)->where('active', true)->orderBy('name')->get();
        $laborRoles = CatalogLaborRole::query()->forOrganization($organizationId)->where('active', true)->orderBy('name')->get();
        $group = in_array($request->string('group')->toString(), ['location', 'system', 'phase', 'category', 'type'], true) ? $request->string('group')->toString() : 'location';
        $canViewCost = Gate::allows('viewCostMargin', $quote);

        return view('office.quotes.show', compact('quote', 'revision', 'services', 'products', 'packages', 'categories', 'units', 'laborRoles', 'group', 'canViewCost'));
    }

    public function update(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote, $revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer', 'min:1'], 'discount_type' => ['nullable', Rule::in(['fixed', 'percent'])], 'discount_value' => ['nullable', 'integer', 'min:0'], 'tax_rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'], 'tax_override_reason' => ['nullable', 'string', 'max:1000']]);
        $workflow->updateRevision($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Quote totals updated.');
    }

    public function addCatalogLine(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'catalog_selection' => ['required', 'string'], 'quantity_millis' => ['required', 'integer', 'min:1'], 'location_id' => ['nullable', 'integer'], 'system_id' => ['nullable', 'integer'], 'phase_id' => ['nullable', 'integer'], 'optional' => ['nullable', 'boolean']]);
        $parts = explode(':', $data['catalog_selection']);
        if (count($parts) < 2 || ! in_array($parts[0], ['service', 'product', 'package'], true) || ! ctype_digit($parts[1])) {
            return back()->withErrors(['catalog_selection' => 'Choose a valid Catalog item.']);
        }
        $data['catalog_item_type'] = $parts[0];
        $data['catalog_item_id'] = (int) $parts[1];
        $data['catalog_service_variant_id'] = isset($parts[2]) && ctype_digit($parts[2]) ? (int) $parts[2] : null;
        $workflow->addCatalogLine($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Catalog line added.');
    }

    public function addAllowance(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'description' => ['required', 'string', 'max:255'], 'customer_description' => ['nullable', 'string', 'max:2000'], 'amount_cents' => ['required', 'integer', 'min:0'], 'location_id' => ['nullable', 'integer'], 'system_id' => ['nullable', 'integer'], 'phase_id' => ['nullable', 'integer'], 'optional' => ['nullable', 'boolean'], 'taxable' => ['nullable', 'boolean']]);
        $workflow->addAllowance($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Allowance added.');
    }

    public function updateLine(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionLine $line, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        abort_unless((int) $line->commercial_revision_id === (int) $revision->id, 404);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'description' => ['required', 'string', 'max:255'], 'customer_description' => ['nullable', 'string', 'max:2000'], 'quantity_millis' => ['required', 'integer', 'min:1'], 'pricing_mode' => ['required', Rule::in(['catalog', 'direct', 'markup', 'margin'])], 'effective_unit_sell_cents' => ['required', 'integer', 'min:0'], 'pricing_value_basis_points' => ['nullable', 'integer', 'min:0', 'max:999999'], 'discount_type' => ['nullable', Rule::in(['fixed', 'percent'])], 'discount_value' => ['nullable', 'integer', 'min:0'], 'location_id' => ['nullable', 'integer'], 'system_id' => ['nullable', 'integer'], 'phase_id' => ['nullable', 'integer'], 'optional' => ['nullable', 'boolean'], 'included' => ['nullable', 'boolean'], 'taxable' => ['nullable', 'boolean']]);
        $workflow->updateLine($revision, $line, $request->user(), $data);

        return $this->back($quote, $revision, 'Line updated.');
    }

    public function removeLine(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionLine $line, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        abort_unless((int) $line->commercial_revision_id === (int) $revision->id, 404);
        $data = $request->validate(['content_version' => ['required', 'integer']]);
        $workflow->removeLine($revision, $line, $request->user(), (int) $data['content_version']);

        return $this->back($quote, $revision, 'Line removed.');
    }

    public function copyLine(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionLine $line, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        abort_unless((int) $line->commercial_revision_id === (int) $revision->id, 404);
        $data = $request->validate(['content_version' => ['required', 'integer']]);
        $workflow->copyLine($revision, $line, $request->user(), (int) $data['content_version']);

        return $this->back($quote, $revision, 'Line copied.');
    }

    public function bulkAssign(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'line_ids' => ['required', 'array', 'min:1'], 'line_ids.*' => ['integer'], 'location_id' => ['nullable', 'integer'], 'system_id' => ['nullable', 'integer'], 'phase_id' => ['nullable', 'integer']]);
        $workflow->bulkAssign($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Selected lines moved.');
    }

    public function updateComponent(Request $request, CommercialDocument $quote, CommercialRevision $revision, CommercialRevisionLine $line, CommercialRevisionLineComponent $component, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        abort_unless((int) $line->commercial_revision_id === (int) $revision->id && (int) $component->commercial_revision_line_id === (int) $line->id, 404);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'], 'quantity_millis' => ['required', 'integer', 'min:1'], 'waste_basis_points' => ['required', 'integer', 'min:0', 'max:10000'], 'customer_visible' => ['nullable', 'boolean']]);
        $workflow->updateComponent($revision, $line, $component, $request->user(), $data);

        return $this->back($quote, $revision, 'Package component updated.');
    }

    public function addDimension(Request $request, CommercialDocument $quote, CommercialRevision $revision, string $type, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'parent_id' => ['nullable', 'integer']]);
        $workflow->addDimension($revision, $request->user(), $type, $data);

        return $this->back($quote, $revision, 'Quote dimension added.');
    }

    public function addSection(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'heading' => ['required', 'string', 'max:255'], 'body' => ['nullable', 'string', 'max:10000'], 'customer_visible' => ['nullable', 'boolean']]);
        $workflow->addSection($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Scope section added.');
    }

    public function addMilestone(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120'], 'amount_type' => ['required', Rule::in(['fixed', 'percent'])], 'amount_value' => ['required', 'integer', 'min:0'], 'is_balancing' => ['nullable', 'boolean']]);
        $workflow->addMilestone($revision, $request->user(), $data);

        return $this->back($quote, $revision, 'Payment milestone added.');
    }

    public function lock(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $data = $request->validate(['content_version' => ['required', 'integer'], 'confirm' => ['accepted']]);
        $workflow->lockForHistory($revision, $request->user(), (int) $data['content_version']);

        return $this->back($quote, $revision, 'Revision locked for history.');
    }

    public function clone(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteWorkflow $workflow): RedirectResponse
    {
        [$quote,$revision] = $this->scoped($request, $quote, $revision);
        Gate::authorize('update', $quote);
        $new = $workflow->cloneDraft($revision, $request->user());

        return redirect()->route('office.quotes.show', [$quote, $new])->with('status', 'New Draft revision created.');
    }

    private function opportunity(Request $request, Opportunity $opportunity): Opportunity
    {
        $organization = $request->attributes->get('organization');

        return Opportunity::query()->forOrganization($organization->id)->findOrFail($opportunity->id);
    }

    private function scoped(Request $request, CommercialDocument $quote, CommercialRevision $revision): array
    {
        $organization = $request->attributes->get('organization');
        $quote = CommercialDocument::query()->forOrganization($organization->id)->where('document_type', 'quote')->findOrFail($quote->id);
        $revision = $quote->revisions()->whereKey($revision->id)->firstOrFail();

        return [$quote, $revision];
    }

    private function back(CommercialDocument $quote, CommercialRevision $revision, string $status): RedirectResponse
    {
        return redirect()->route('office.quotes.show', [$quote, $revision])->with('status', $status);
    }
}
