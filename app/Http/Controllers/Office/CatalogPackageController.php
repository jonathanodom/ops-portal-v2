<?php

namespace App\Http\Controllers\Office;

use App\Domain\PackageDemandCalculator;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogCategory;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\UnitOfMeasure;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogPackageController extends Controller
{
    use ResolvesCatalogRecords;

    private const PROTECTED_PRICING_FIELDS = ['pricing_model', 'default_price', 'taxable'];

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CatalogPackage::class, $organization]);
        $packages = CatalogPackage::query()->forOrganization($organization->id)->with(['category', 'salesUom'])->withCount('components')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%')->orWhere('package_code', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->filled('pricing_model'), fn ($query) => $query->where('pricing_model', $request->string('pricing_model')))
            ->when($request->filled('status'), fn ($query) => $query->where('active', $request->string('status')->value() === 'active'))
            ->orderBy('name')->paginate(25)->withQueryString();
        $categories = CatalogCategory::query()->forOrganization($organization->id)->where('active', true)->orderBy('name')->get();

        return view('office.catalog.packages.index', compact('packages', 'categories'));
    }

    public function show(Request $request, string $package, PackageDemandCalculator $calculator): View
    {
        $package = $this->package($request, $package);
        Gate::authorize('view', $package);
        $package->load(['category', 'salesUom', 'components.product.baseUom', 'components.service.salesUom', 'components.componentUom']);
        $quantity = (string) $request->input('quantity', '1');
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $quantity) || $this->decimalToMillis($quantity) < 1) {
            throw ValidationException::withMessages(['quantity' => 'Enter a package quantity greater than zero with no more than three decimal places.']);
        }
        $quantityMillis = $this->decimalToMillis($quantity);
        $demand = $calculator->calculate($package, $quantityMillis);
        $currentProductIds = $package->components->pluck('catalog_product_id')->filter()->all();
        $currentServiceIds = $package->components->pluck('catalog_service_id')->filter()->all();
        $products = CatalogProduct::query()->forOrganization($package->organization_id)->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $currentProductIds))->with('baseUom')->orderBy('name')->get();
        $services = CatalogService::query()->forOrganization($package->organization_id)->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $currentServiceIds))->with('salesUom')->orderBy('name')->get();
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.packages.show', compact('package', 'quantity', 'quantityMillis', 'demand', 'products', 'services', 'canManagePricing'));
    }

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogPackage::class, $organization]);
        [$categories, $units] = $this->options($organization->id);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.packages.create', compact('categories', 'units', 'canManagePricing'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogPackage::class, $organization]);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $organization->id, null, $canManagePricing);
        $package = DB::transaction(fn (): CatalogPackage => CatalogPackage::query()->create($data + ['organization_id' => $organization->id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]));
        $audit->record($organization, $request->user(), 'catalog.package_created', $package, ['package_id' => $package->id, 'pricing_model' => $package->pricing_model, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.packages.show', $package)->with('status', 'Package created. Add Products and Services to its standard recipe.');
    }

    public function edit(Request $request, string $package): View
    {
        $package = $this->package($request, $package);
        Gate::authorize('update', $package);
        [$categories, $units] = $this->options($package->organization_id, true);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.packages.edit', compact('package', 'categories', 'units', 'canManagePricing'));
    }

    public function update(Request $request, string $package, AuditRecorder $audit): RedirectResponse
    {
        $package = $this->package($request, $package);
        Gate::authorize('update', $package);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $package->organization_id, $package, $canManagePricing);
        $changed = collect($data)->filter(fn ($value, $field) => $package->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($package, $request, $data): void {
            CatalogPackage::query()->lockForUpdate()->findOrFail($package->id)->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.package_updated', $package, ['package_id' => $package->id, 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.packages.show', $package)->with('status', 'Package saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, ?CatalogPackage $package, bool $canManagePricing): array
    {
        if (! $canManagePricing && $request->hasAny(self::PROTECTED_PRICING_FIELDS)) {
            abort(403);
        }
        $request->merge(['package_code' => strtoupper((string) $request->input('package_code'))]);
        $data = $request->validate([
            'package_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_packages')->where('organization_id', $organizationId)->ignore($package?->id)],
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'integer', Rule::exists('catalog_categories', 'id')->where('organization_id', $organizationId)],
            'sales_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where(function ($query) use ($organizationId, $package): void {
                $query->where('organization_id', $organizationId)->where(function ($query) use ($package): void {
                    $query->where('active', true);
                    if ($package) {
                        $query->orWhere('id', $package->sales_uom_id);
                    }
                });
            })],
            'customer_description' => ['nullable', 'string', 'max:5000'],
            'internal_description' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
        ] + ($canManagePricing ? [
            'pricing_model' => ['required', Rule::in(CatalogPackage::PRICING_MODELS)],
            'default_price' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'taxable' => ['nullable', 'boolean'],
        ] : []));
        $data['category_id'] = filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null;
        $data['active'] = $request->boolean('active');
        if ($canManagePricing) {
            if ($data['pricing_model'] === 'flat' && blank($data['default_price'])) {
                throw ValidationException::withMessages(['default_price' => 'A flat-price Package requires a default price.']);
            }
            $data['default_price_cents'] = $data['pricing_model'] === 'flat' ? $this->dollarsToCents((string) $data['default_price']) : null;
            $data['taxable'] = $request->boolean('taxable');
            unset($data['default_price']);
        } elseif ($package) {
            $data += $package->only(['pricing_model', 'default_price_cents', 'taxable']);
        } else {
            $data += ['pricing_model' => 'quote_required', 'default_price_cents' => null, 'taxable' => true];
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

    private function package(Request $request, string $id): CatalogPackage
    {
        /** @var CatalogPackage */
        return $this->catalogRecord($request, CatalogPackage::class, $id, 'catalog_package');
    }
}
