<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\UnitOfMeasure;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CatalogProductController extends Controller
{
    use ResolvesCatalogRecords;

    private const PROTECTED_PRICING_FIELDS = ['default_cost', 'default_cost_quantity', 'default_sell_price', 'taxable'];

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CatalogProduct::class, $organization]);
        $products = CatalogProduct::query()->forOrganization($organization->id)
            ->with(['category', 'baseUom', 'defaultSalesUom'])->withCount('purchaseUnits')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($query) => $query->where('name', 'like', $term)
                    ->orWhere('product_code', 'like', $term)->orWhere('sku', 'like', $term)
                    ->orWhere('manufacturer', 'like', $term)->orWhere('model', 'like', $term));
            })
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->filled('status'), fn ($query) => $query->where('active', $request->string('status')->value() === 'active'))
            ->orderBy('name')->paginate(25)->withQueryString();
        $categories = CatalogCategory::query()->forOrganization($organization->id)->where('active', true)->orderBy('name')->get();

        return view('office.catalog.products.index', compact('products', 'categories'));
    }

    public function show(Request $request, string $product): View
    {
        $product = $this->product($request, $product);
        Gate::authorize('view', $product);
        $product->load(['category', 'baseUom', 'defaultSalesUom', 'purchaseUnits.purchaseUom']);
        $units = UnitOfMeasure::query()->forOrganization($product->organization_id)->where('active', true)->orderBy('dimension')->orderBy('name')->get();
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.products.show', compact('product', 'units', 'canManagePricing'));
    }

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogProduct::class, $organization]);
        [$categories, $units] = $this->options($organization->id);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.products.create', compact('categories', 'units', 'canManagePricing'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogProduct::class, $organization]);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $organization->id, null, $canManagePricing);
        $product = DB::transaction(fn (): CatalogProduct => CatalogProduct::query()->create($data + [
            'organization_id' => $organization->id,
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]));
        $audit->record($organization, $request->user(), 'catalog.product_created', $product, ['product_id' => $product->id, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.products.show', $product)->with('status', 'Product created. Add purchase units when this item is bought by box, roll, bag, or case.');
    }

    public function edit(Request $request, string $product): View
    {
        $product = $this->product($request, $product);
        Gate::authorize('update', $product);
        [$categories, $units] = $this->options($product->organization_id, true);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.products.edit', compact('product', 'categories', 'units', 'canManagePricing'));
    }

    public function update(Request $request, string $product, AuditRecorder $audit): RedirectResponse
    {
        $product = $this->product($request, $product);
        Gate::authorize('update', $product);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $product->organization_id, $product, $canManagePricing);
        $changed = collect($data)->filter(fn ($value, $field) => $product->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($product, $request, $data): void {
            CatalogProduct::query()->lockForUpdate()->findOrFail($product->id)->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.product_updated', $product, ['product_id' => $product->id, 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.products.show', $product)->with('status', 'Product saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, ?CatalogProduct $product, bool $canManagePricing): array
    {
        if (! $canManagePricing && $request->hasAny(self::PROTECTED_PRICING_FIELDS)) {
            abort(403);
        }
        $request->merge(['product_code' => strtoupper((string) $request->input('product_code'))]);
        $unitRule = function (?int $currentId = null) use ($organizationId) {
            return Rule::exists('units_of_measure', 'id')->where(function ($query) use ($organizationId, $currentId): void {
                $query->where('organization_id', $organizationId)->where(function ($query) use ($currentId): void {
                    $query->where('active', true);
                    if ($currentId) {
                        $query->orWhere('id', $currentId);
                    }
                });
            });
        };
        $data = $request->validate([
            'product_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_products')->where('organization_id', $organizationId)->ignore($product?->id)],
            'sku' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:160'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('catalog_categories', 'id')->where('organization_id', $organizationId)],
            'base_uom_id' => ['required', 'integer', $unitRule($product?->base_uom_id)],
            'default_sales_uom_id' => ['required', 'integer', $unitRule($product?->default_sales_uom_id)],
            'sales_quantity' => ['required', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'customer_description' => ['nullable', 'string', 'max:5000'],
            'internal_description' => ['nullable', 'string', 'max:5000'],
            'tracking_type' => ['required', Rule::in(CatalogProduct::TRACKING_TYPES)],
            'active' => ['nullable', 'boolean'],
        ] + ($canManagePricing ? [
            'default_cost' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'default_cost_quantity' => ['required', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'default_sell_price' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'taxable' => ['nullable', 'boolean'],
        ] : []));

        $data['category_id'] = filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null;
        $data['sales_quantity_millis'] = $this->decimalToMillis((string) $data['sales_quantity']);
        $data['active'] = $request->boolean('active');
        unset($data['sales_quantity']);
        if ($canManagePricing) {
            $data['default_cost_cents'] = filled($data['default_cost'] ?? null) ? $this->dollarsToCents((string) $data['default_cost']) : null;
            $data['default_cost_quantity_millis'] = $this->decimalToMillis((string) $data['default_cost_quantity']);
            $data['default_sell_price_cents'] = filled($data['default_sell_price'] ?? null) ? $this->dollarsToCents((string) $data['default_sell_price']) : null;
            $data['taxable'] = $request->boolean('taxable');
            unset($data['default_cost'], $data['default_cost_quantity'], $data['default_sell_price']);
        } elseif ($product) {
            $data += $product->only(['default_cost_cents', 'default_cost_quantity_millis', 'default_sell_price_cents', 'taxable']);
        } else {
            $data += ['default_cost_cents' => null, 'default_cost_quantity_millis' => 1000, 'default_sell_price_cents' => null, 'taxable' => true];
        }

        return $data;
    }

    /** @return array{0: Collection, 1: Collection} */
    private function options(int $organizationId, bool $includeInactive = false): array
    {
        return [
            CatalogCategory::query()->forOrganization($organizationId)->when(! $includeInactive, fn ($query) => $query->where('active', true))->orderBy('name')->get(),
            UnitOfMeasure::query()->forOrganization($organizationId)->when(! $includeInactive, fn ($query) => $query->where('active', true))->orderBy('dimension')->orderBy('name')->get(),
        ];
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function decimalToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }

    private function product(Request $request, string $id): CatalogProduct
    {
        /** @var CatalogProduct */
        return $this->catalogRecord($request, CatalogProduct::class, $id, 'catalog_product');
    }
}
