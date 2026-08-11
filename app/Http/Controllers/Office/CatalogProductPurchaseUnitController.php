<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogProduct;
use App\Models\CatalogProductPurchaseUnit;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogProductPurchaseUnitController extends Controller
{
    use ResolvesCatalogRecords;

    public function store(Request $request, string $product, AuditRecorder $audit): RedirectResponse
    {
        $product = $this->product($request, $product);
        Gate::authorize('update', $product);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $product, null, $canManagePricing);
        $purchaseUnit = DB::transaction(function () use ($request, $product, $data): CatalogProductPurchaseUnit {
            CatalogProduct::query()->lockForUpdate()->findOrFail($product->id);
            if ($data['is_default'] && $data['active']) {
                CatalogProductPurchaseUnit::query()->where('catalog_product_id', $product->id)->lockForUpdate()->update(['is_default' => false, 'updated_by_id' => $request->user()->id]);
            }

            return $product->purchaseUnits()->create($data + ['organization_id' => $product->organization_id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.product_purchase_unit_created', $purchaseUnit, ['product_id' => $product->id, 'purchase_unit_id' => $purchaseUnit->id, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.products.show', $product)->with('status', 'Purchase unit added.');
    }

    public function update(Request $request, string $product, string $purchaseUnit, AuditRecorder $audit): RedirectResponse
    {
        $product = $this->product($request, $product);
        Gate::authorize('update', $product);
        $purchaseUnit = $this->purchaseUnit($request, $product, $purchaseUnit);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $product, $purchaseUnit, $canManagePricing);
        $changed = collect($data)->filter(fn ($value, $field) => $purchaseUnit->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($request, $product, $purchaseUnit, $data): void {
            CatalogProduct::query()->lockForUpdate()->findOrFail($product->id);
            $purchaseUnit = CatalogProductPurchaseUnit::query()->lockForUpdate()->findOrFail($purchaseUnit->id);
            if ($data['is_default'] && $data['active']) {
                CatalogProductPurchaseUnit::query()->where('catalog_product_id', $product->id)->whereKeyNot($purchaseUnit->id)->lockForUpdate()->update(['is_default' => false, 'updated_by_id' => $request->user()->id]);
            }
            if (! $data['active']) {
                $data['is_default'] = false;
            }
            $purchaseUnit->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.product_purchase_unit_updated', $purchaseUnit, ['product_id' => $product->id, 'purchase_unit_id' => $purchaseUnit->id, 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.products.show', $product)->with('status', 'Purchase unit saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CatalogProduct $product, ?CatalogProductPurchaseUnit $purchaseUnit, bool $canManagePricing): array
    {
        if (! $canManagePricing && $request->has('default_purchase_cost')) {
            abort(403);
        }
        $data = $request->validate([
            'purchase_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where(fn ($query) => $query->where('organization_id', $product->organization_id)->where(function ($query) use ($purchaseUnit): void {
                $query->where('active', true);
                if ($purchaseUnit) {
                    $query->orWhere('id', $purchaseUnit->purchase_uom_id);
                }
            }))],
            'label' => ['required', 'string', 'max:120', Rule::unique('catalog_product_purchase_units')->where('catalog_product_id', $product->id)->ignore($purchaseUnit?->id)],
            'base_quantity' => ['required', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'vendor_sku' => ['nullable', 'string', 'max:120'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ] + ($canManagePricing ? ['default_purchase_cost' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/']] : []));
        $data['base_quantity_millis'] = $this->decimalToMillis((string) $data['base_quantity']);
        $data['is_default'] = $request->boolean('is_default');
        $data['active'] = $request->boolean('active');
        if (! $data['active']) {
            $data['is_default'] = false;
        }
        unset($data['base_quantity']);
        if ($canManagePricing) {
            $data['default_purchase_cost_cents'] = filled($data['default_purchase_cost'] ?? null) ? $this->dollarsToCents((string) $data['default_purchase_cost']) : null;
            unset($data['default_purchase_cost']);
        } else {
            $data['default_purchase_cost_cents'] = $purchaseUnit?->default_purchase_cost_cents;
        }

        return $data;
    }

    private function decimalToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function product(Request $request, string $id): CatalogProduct
    {
        /** @var CatalogProduct */
        return $this->catalogRecord($request, CatalogProduct::class, $id, 'catalog_product');
    }

    private function purchaseUnit(Request $request, CatalogProduct $product, string $id): CatalogProductPurchaseUnit
    {
        $purchaseUnit = CatalogProductPurchaseUnit::query()->where('organization_id', $product->organization_id)->where('catalog_product_id', $product->id)->find($id);

        if (! $purchaseUnit && CatalogProductPurchaseUnit::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($request->attributes->get('organization'), $request->user(), 'security.cross_organization_record_denied', $request->attributes->get('organization'), [
                'record_type' => 'catalog_product_purchase_unit',
                'record_id' => (int) $id,
                'product_id' => $product->id,
            ]);
        }

        return $purchaseUnit ?? abort(404);
    }
}
