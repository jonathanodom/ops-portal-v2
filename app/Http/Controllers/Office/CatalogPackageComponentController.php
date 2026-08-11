<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogPackage;
use App\Models\CatalogPackageComponent;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogPackageComponentController extends Controller
{
    use ResolvesCatalogRecords;

    public function store(Request $request, string $package, AuditRecorder $audit): RedirectResponse
    {
        $package = $this->package($request, $package);
        Gate::authorize('update', $package);
        $data = $this->validated($request, $package, null);
        $component = DB::transaction(function () use ($request, $package, $data): CatalogPackageComponent {
            CatalogPackage::query()->lockForUpdate()->findOrFail($package->id);

            return $package->components()->create($data + ['organization_id' => $package->organization_id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.package_component_created', $component, ['package_id' => $package->id, 'component_id' => $component->id, 'component_type' => $component->component_type, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.packages.show', $package)->with('status', 'Recipe component added.');
    }

    public function update(Request $request, string $package, string $component, AuditRecorder $audit): RedirectResponse
    {
        $package = $this->package($request, $package);
        Gate::authorize('update', $package);
        $component = $this->component($request, $package, $component);
        $data = $this->validated($request, $package, $component);
        $changed = collect($data)->filter(fn ($value, $field) => $component->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($request, $package, $component, $data): void {
            CatalogPackage::query()->lockForUpdate()->findOrFail($package->id);
            CatalogPackageComponent::query()->lockForUpdate()->findOrFail($component->id)->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.package_component_updated', $component, ['package_id' => $package->id, 'component_id' => $component->id, 'component_type' => $data['component_type'], 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.packages.show', $package)->with('status', 'Recipe component saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CatalogPackage $package, ?CatalogPackageComponent $component): array
    {
        $data = $request->validate([
            'component_type' => ['required', Rule::in(CatalogPackageComponent::TYPES)],
            'component_id' => ['required', 'integer'],
            'quantity_basis' => ['nullable', Rule::in(CatalogPackageComponent::QUANTITY_BASES)],
            'quantity' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'basis_count' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'basis_quantity' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,3})?$/', 'not_in:0,0.0,0.00,0.000'],
            'waste_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'customer_visible' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'boolean'],
        ]);
        $source = $this->source($package, $data['component_type'], (int) $data['component_id'], $component);
        $sourceField = $data['component_type'] === 'product' ? 'catalog_product_id' : 'catalog_service_id';
        $duplicate = CatalogPackageComponent::query()->where('catalog_package_id', $package->id)->where($sourceField, $source->id)->when($component, fn ($query) => $query->whereKeyNot($component->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['component_id' => 'This item is already in the Package recipe. Edit the existing component instead.']);
        }
        $wasteBasisPoints = $this->percentToBasisPoints((string) ($data['waste_percent'] ?? '0'));
        if ($wasteBasisPoints > 10000) {
            throw ValidationException::withMessages(['waste_percent' => 'Waste may not exceed 100 percent.']);
        }
        if ($data['component_type'] === 'service' && $wasteBasisPoints !== 0) {
            throw ValidationException::withMessages(['waste_percent' => 'Waste applies only to Product components.']);
        }
        $quantityBasis = (string) ($data['quantity_basis'] ?? 'direct');
        if ($data['component_type'] === 'service' && $quantityBasis !== 'direct') {
            throw ValidationException::withMessages(['quantity_basis' => 'Service components use a direct standard quantity.']);
        }
        if ($quantityBasis === 'direct') {
            if (blank($data['quantity'] ?? null)) {
                throw ValidationException::withMessages(['quantity' => 'Enter the standard quantity for this component.']);
            }
            $quantityMillis = $this->decimalToMillis((string) $data['quantity']);
            $basisCountMillis = null;
            $basisQuantityMillis = null;
        } else {
            if (blank($data['basis_count'] ?? null) || blank($data['basis_quantity'] ?? null)) {
                throw ValidationException::withMessages(['basis_count' => 'Pull-allowance components require a pull count and standard quantity per pull.']);
            }
            $basisCountMillis = $this->decimalToMillis((string) $data['basis_count']);
            $basisQuantityMillis = $this->decimalToMillis((string) $data['basis_quantity']);
            $quantityMillis = $this->multiplyAndRound($basisCountMillis, $basisQuantityMillis, 1000);
        }

        return [
            'component_type' => $data['component_type'],
            'catalog_product_id' => $data['component_type'] === 'product' ? $source->id : null,
            'catalog_service_id' => $data['component_type'] === 'service' ? $source->id : null,
            'component_uom_id' => $data['component_type'] === 'product' ? $source->base_uom_id : $source->sales_uom_id,
            'quantity_basis' => $quantityBasis,
            'quantity_millis' => $quantityMillis,
            'basis_count_millis' => $basisCountMillis,
            'basis_quantity_millis' => $basisQuantityMillis,
            'waste_basis_points' => $wasteBasisPoints,
            'customer_visible' => $request->boolean('customer_visible'),
            'sort_order' => (int) $data['sort_order'],
            'internal_notes' => $data['internal_notes'] ?? null,
            'active' => $request->boolean('active'),
        ];
    }

    private function source(CatalogPackage $package, string $type, int $id, ?CatalogPackageComponent $component): Model
    {
        $modelClass = $type === 'product' ? CatalogProduct::class : CatalogService::class;
        $currentId = $type === 'product' ? $component?->catalog_product_id : $component?->catalog_service_id;
        $source = $modelClass::query()->where('organization_id', $package->organization_id)->where(function ($query) use ($currentId): void {
            $query->where('active', true);
            if ($currentId) {
                $query->orWhere('id', $currentId);
            }
        })->find($id);

        if (! $source) {
            throw ValidationException::withMessages(['component_id' => 'Select an active item from this Organization.']);
        }

        return $source;
    }

    private function decimalToMillis(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000) + (int) str_pad(substr($decimal, 0, 3), 3, '0');
    }

    private function percentToBasisPoints(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function multiplyAndRound(int $left, int $right, int $divisor): int
    {
        $rounding = intdiv($divisor, 2);
        if ($left > intdiv(PHP_INT_MAX - $rounding, $right)) {
            throw ValidationException::withMessages(['basis_count' => 'The pull allowance is too large to calculate safely.']);
        }

        return intdiv(($left * $right) + $rounding, $divisor);
    }

    private function package(Request $request, string $id): CatalogPackage
    {
        /** @var CatalogPackage */
        return $this->catalogRecord($request, CatalogPackage::class, $id, 'catalog_package');
    }

    private function component(Request $request, CatalogPackage $package, string $id): CatalogPackageComponent
    {
        $component = CatalogPackageComponent::query()->where('organization_id', $package->organization_id)->where('catalog_package_id', $package->id)->find($id);
        if (! $component && CatalogPackageComponent::query()->whereKey($id)->exists()) {
            app(AuditRecorder::class)->record($request->attributes->get('organization'), $request->user(), 'security.cross_organization_record_denied', $request->attributes->get('organization'), ['record_type' => 'catalog_package_component', 'record_id' => (int) $id, 'package_id' => $package->id]);
        }

        return $component ?? abort(404);
    }
}
