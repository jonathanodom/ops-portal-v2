<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\QuoteCatalogItemWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CommercialDocument;
use App\Models\CommercialRevision;
use App\Support\FixedPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class QuoteCatalogItemController extends Controller
{
    public function store(Request $request, CommercialDocument $quote, CommercialRevision $revision, QuoteCatalogItemWorkflow $workflow): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        abort_unless((int) $quote->organization_id === (int) $organization->id && (int) $revision->commercial_document_id === (int) $quote->id, 404);
        Gate::authorize('update', $quote);
        $membership = $request->attributes->get('membership');
        abort_unless($membership->hasCapability('catalog.manage') && $membership->hasCapability('catalog.pricing.manage'), 403);
        $request->merge(['item_code' => strtoupper((string) $request->input('item_code'))]);
        $base = $request->validate([
            'content_version' => ['required', 'integer', 'min:1'],
            'item_type' => ['required', Rule::in(['product', 'service', 'package'])],
            'item_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required', 'string', 'max:160'],
            'customer_description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', Rule::exists('catalog_categories', 'id')->where('organization_id', $organization->id)],
            'sales_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where(fn ($query) => $query->where('organization_id', $organization->id)->where('active', true))],
            'default_price' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'default_internal_cost' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'default_labor_role_id' => ['nullable', 'integer', Rule::exists('catalog_labor_roles', 'id')->where(fn ($query) => $query->where('organization_id', $organization->id)->where('active', true))],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'quantity' => ['required', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'taxable' => ['nullable', 'boolean'], 'optional' => ['nullable', 'boolean'],
        ]);
        $type = $base['item_type'];
        $codeColumn = match ($type) {
            'product' => 'product_code', 'service' => 'service_code', default => 'package_code'
        };
        $table = match ($type) {
            'product' => 'catalog_products', 'service' => 'catalog_services', default => 'catalog_packages'
        };
        $request->validate(['item_code' => [Rule::unique($table, $codeColumn)->where('organization_id', $organization->id)]]);
        $price = $this->dollarsToCents($base['default_price']);
        $common = ['category_id' => filled($base['category_id'] ?? null) ? (int) $base['category_id'] : null, 'name' => $base['name'], 'customer_description' => $base['customer_description'] ?? null, 'taxable' => $request->boolean('taxable')];
        $catalogData = match ($type) {
            'product' => $common + ['product_code' => $base['item_code'], 'base_uom_id' => (int) $base['sales_uom_id'], 'default_sales_uom_id' => (int) $base['sales_uom_id'], 'sales_quantity_millis' => 1000, 'default_cost_cents' => filled($base['default_internal_cost'] ?? null) ? $this->dollarsToCents($base['default_internal_cost']) : null, 'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => $price, 'tracking_type' => 'standard'],
            'service' => $common + ['service_code' => $base['item_code'], 'sales_uom_id' => (int) $base['sales_uom_id'], 'pricing_model' => filled($base['estimated_duration_minutes'] ?? null) ? 'flat' : 'hourly', 'default_price_cents' => $price, 'default_internal_cost_cents' => filled($base['default_internal_cost'] ?? null) ? $this->dollarsToCents($base['default_internal_cost']) : null, 'default_labor_role_id' => filled($base['default_labor_role_id'] ?? null) ? (int) $base['default_labor_role_id'] : null, 'estimated_duration_minutes' => filled($base['estimated_duration_minutes'] ?? null) ? (int) $base['estimated_duration_minutes'] : null, 'customer_visible' => true, 'requires_office_approval' => false],
            'package' => $common + ['package_code' => $base['item_code'], 'sales_uom_id' => (int) $base['sales_uom_id'], 'pricing_model' => 'flat', 'default_price_cents' => $price],
        };
        $workflow->createAndAdd($organization, $revision, $request->user(), $type, $catalogData, [
            'content_version' => (int) $base['content_version'], 'quantity_millis' => FixedPoint::quantityToMillis($base['quantity']),
            'optional' => $request->boolean('optional'),
        ]);

        return redirect()->route('office.quotes.show', [$quote, $revision])->with('status', 'Catalog item created and added to the Quote.');
    }

    private function dollarsToCents(string $value): int
    {
        return FixedPoint::dollarsToCents($value);
    }
}
