<?php

namespace App\Domain;

use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use Illuminate\Validation\ValidationException;

class CatalogLineSnapshotFactory
{
    public const TYPES = ['service', 'product', 'package'];

    public function __construct(
        private readonly CatalogPricingResolver $pricing,
        private readonly PackageDemandCalculator $packageDemand,
    ) {}

    /** @return array<string, mixed> */
    public function create(int $organizationId, string $type, int $itemId, int $quantityMillis, ?int $variantId = null): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['catalog_item_type' => 'Choose a valid Catalog item type.']);
        }
        if ($quantityMillis < 1) {
            throw ValidationException::withMessages(['catalog_quantity' => 'Quantity must be greater than zero.']);
        }

        return match ($type) {
            'service' => $this->service($organizationId, $itemId, $quantityMillis, $variantId),
            'product' => $this->product($organizationId, $itemId, $quantityMillis),
            'package' => $this->package($organizationId, $itemId, $quantityMillis),
        };
    }

    /** @return array<string, mixed> */
    private function service(int $organizationId, int $itemId, int $quantityMillis, ?int $variantId): array
    {
        $service = CatalogService::query()->forOrganization($organizationId)->where('active', true)->with('salesUom')->find($itemId);
        if (! $service) {
            throw ValidationException::withMessages(['catalog_item_id' => 'Choose an active Service from this Organization.']);
        }
        if ($variantId && $service->pricing_model !== 'variant') {
            throw ValidationException::withMessages(['catalog_service_variant_id' => 'The selected Service does not use Variants.']);
        }
        $variant = null;
        if ($variantId) {
            $variant = CatalogServiceVariant::query()->forOrganization($organizationId)->where('catalog_service_id', $service->id)->where('active', true)->find($variantId);
            if (! $variant) {
                throw ValidationException::withMessages(['catalog_service_variant_id' => 'Choose an active Variant for the selected Service.']);
            }
        }
        $price = $this->pricing->servicePrice($service, $variant);
        $variantLabel = $variant?->customer_label ?: $variant?->label;
        $name = $variantLabel ? $service->name.' — '.$variantLabel : $service->name;
        $code = $variant ? $service->service_code.':'.$variant->code : $service->service_code;

        return $this->snapshot(
            'service', $quantityMillis, $code, $name,
            $service->customer_description ?: $name,
            $service->salesUom->code, $service->salesUom->name,
            $price, $service->taxable,
            ['catalog_service_id' => $service->id, 'catalog_service_variant_id' => $variant?->id],
        );
    }

    /** @return array<string, mixed> */
    private function product(int $organizationId, int $itemId, int $quantityMillis): array
    {
        $product = CatalogProduct::query()->forOrganization($organizationId)->where('active', true)->with('defaultSalesUom')->find($itemId);
        if (! $product) {
            throw ValidationException::withMessages(['catalog_item_id' => 'Choose an active Product from this Organization.']);
        }
        $unit = $product->defaultSalesUom;

        return $this->snapshot(
            'product', $quantityMillis, $product->product_code, $product->name,
            $product->customer_description ?: $product->name,
            $unit->code, $unit->name,
            $product->default_sell_price_cents === null ? null : (int) $product->default_sell_price_cents,
            $product->taxable,
            ['catalog_product_id' => $product->id],
        );
    }

    /** @return array<string, mixed> */
    private function package(int $organizationId, int $itemId, int $quantityMillis): array
    {
        $package = CatalogPackage::query()->forOrganization($organizationId)->where('active', true)
            ->with(['salesUom', 'components.product', 'components.service', 'components.componentUom'])->find($itemId);
        if (! $package) {
            throw ValidationException::withMessages(['catalog_item_id' => 'Choose an active Package from this Organization.']);
        }
        $recipe = $package->components->where('active', true)->map(function ($component): array {
            $item = $component->component_type === 'product' ? $component->product : $component->service;

            return [
                'type' => $component->component_type,
                'source_id' => $item?->id,
                'code' => $component->component_type === 'product' ? $item?->product_code : $item?->service_code,
                'name' => $item?->name,
                'uom_code' => $component->componentUom->code,
                'uom_name' => $component->componentUom->name,
                'quantity_basis' => $component->quantity_basis,
                'quantity_millis' => (int) $component->quantity_millis,
                'basis_count_millis' => $component->basis_count_millis === null ? null : (int) $component->basis_count_millis,
                'basis_quantity_millis' => $component->basis_quantity_millis === null ? null : (int) $component->basis_quantity_millis,
                'waste_basis_points' => (int) $component->waste_basis_points,
                'customer_visible' => (bool) $component->customer_visible,
            ];
        })->values()->all();
        $demand = $this->packageDemand->calculate($package, $quantityMillis);

        $price = $package->default_price_cents === null ? null : (int) $package->default_price_cents;
        if ($package->pricing_model === 'component_sum') {
            $price = 0;
            foreach ($package->components->where('active', true) as $component) {
                $item = $component->component_type === 'product' ? $component->product : $component->service;
                $unitPrice = $component->component_type === 'product' ? $item?->default_sell_price_cents : $item?->default_price_cents;
                if ($unitPrice === null) {
                    $price = null;
                    break;
                }
                $withWaste = $component->component_type === 'product'
                    ? intdiv(((int) $component->quantity_millis * (10000 + (int) $component->waste_basis_points)) + 5000, 10000)
                    : (int) $component->quantity_millis;
                $price += intdiv(($withWaste * (int) $unitPrice) + 500, 1000);
            }
        }

        return $this->snapshot(
            'package', $quantityMillis, $package->package_code, $package->name,
            $package->customer_description ?: $package->name,
            $package->salesUom->code, $package->salesUom->name,
            $price,
            $package->taxable,
            [
                'catalog_package_id' => $package->id,
                'catalog_package_pricing_model' => $package->pricing_model,
                'catalog_package_recipe_snapshot' => [
                    'package_sales_uom' => ['code' => $package->salesUom->code, 'name' => $package->salesUom->name],
                    'selected_quantity_millis' => $quantityMillis,
                    'recipe' => $recipe,
                    'expected_product_demand' => $demand['products']->values()->all(),
                    'expected_service_demand' => $demand['services']->values()->all(),
                ],
            ],
        );
    }

    /** @param array<string, mixed> $sources @return array<string, mixed> */
    private function snapshot(string $type, int $quantityMillis, string $code, string $name, string $description, string $unitCode, string $unitName, ?int $price, bool $taxable, array $sources): array
    {
        return $sources + [
            'catalog_item_type' => $type,
            'catalog_code_snapshot' => $code,
            'catalog_name_snapshot' => $name,
            'catalog_description_snapshot' => $description,
            'catalog_unit_code_snapshot' => $unitCode,
            'catalog_unit_name_snapshot' => $unitName,
            'catalog_quantity_millis' => $quantityMillis,
            'catalog_original_unit_price_cents' => $price,
            'catalog_unit_price_cents' => $price,
            'catalog_taxable' => $taxable,
        ];
    }
}
