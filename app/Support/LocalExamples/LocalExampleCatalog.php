<?php

namespace App\Support\LocalExamples;

use App\Domain\CatalogDefaults;
use App\Domain\NewDayCatalogBootstrap;
use App\Domain\PackageDemandCalculator;
use App\Models\CatalogCategory;
use App\Models\CatalogPackage;
use App\Models\CatalogPackageComponent;
use App\Models\CatalogProduct;
use App\Models\CatalogProductPurchaseUnit;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Organization;
use App\Models\UnitOfMeasure;
use App\Models\User;
use RuntimeException;

final class LocalExampleCatalog
{
    public function __construct(
        private readonly CatalogDefaults $defaults,
        private readonly NewDayCatalogBootstrap $newDay,
        private readonly PackageDemandCalculator $demand,
    ) {}

    /** @return array{managedService: CatalogService, tvService: CatalogService, tvVariant: CatalogServiceVariant, package: CatalogPackage} */
    public function create(Organization $organization, User $actor): array
    {
        $this->defaults->ensureFor($organization);
        $this->newDay->bootstrap($organization, $actor);
        $category = CatalogCategory::query()->create([
            'organization_id' => $organization->id, 'code' => 'EXAMPLE-INSTALL', 'name' => 'EXAMPLE — Installation Materials',
            'description' => 'Synthetic local-only Catalog records.', 'sort_order' => 900, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);
        $foot = UnitOfMeasure::query()->where('organization_id', $organization->id)->where('code', 'foot')->firstOrFail();
        $box = UnitOfMeasure::query()->where('organization_id', $organization->id)->where('code', 'box')->firstOrFail();
        $location = UnitOfMeasure::query()->where('organization_id', $organization->id)->where('code', 'location')->firstOrFail();
        $month = UnitOfMeasure::query()->where('organization_id', $organization->id)->where('code', 'month')->firstOrFail();
        $visit = UnitOfMeasure::query()->where('organization_id', $organization->id)->where('code', 'visit')->firstOrFail();

        $tvService = CatalogService::query()->create([
            'organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $visit->id,
            'service_code' => 'EXAMPLE-TV-MOUNT', 'name' => 'EXAMPLE — TV Mounting',
            'customer_description' => 'Professional television mounting example.', 'pricing_model' => 'variant',
            'taxable' => true, 'customer_visible' => true, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);
        $tvVariant = CatalogServiceVariant::query()->create([
            'organization_id' => $organization->id, 'catalog_service_id' => $tvService->id,
            'code' => 'EXAMPLE-TV-55', 'label' => 'Up to 55 inches', 'customer_label' => 'TV mounting — up to 55 inches',
            'price_override_cents' => 22500, 'estimated_duration_minutes' => 90, 'sort_order' => 10, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);
        CatalogServiceVariant::query()->create([
            'organization_id' => $organization->id, 'catalog_service_id' => $tvService->id,
            'code' => 'EXAMPLE-TV-85', 'label' => '56–85 inches', 'customer_label' => 'TV mounting — 56 to 85 inches',
            'price_override_cents' => 37500, 'estimated_duration_minutes' => 150, 'sort_order' => 20, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);
        $managedService = CatalogService::query()->create([
            'organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $month->id,
            'service_code' => 'EXAMPLE-MANAGED-WIFI', 'name' => 'EXAMPLE — Managed Wi-Fi Care',
            'customer_description' => 'Synthetic recurring support enrollment.', 'pricing_model' => 'recurring',
            'default_price_cents' => 14900, 'taxable' => false, 'customer_visible' => true,
            'billing_cadence' => 'monthly', 'billing_interval' => 1, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);

        $products = [];
        foreach ([
            ['EXAMPLE-CAT6-BLUE', 'Blue Cat6 Cable', 18900, 45000],
            ['EXAMPLE-CAT6-YELLOW', 'Yellow Cat6 Cable', 18900, 45000],
            ['EXAMPLE-WIRE-16-2', '16/2 Speaker Wire', 14900, 36000],
            ['EXAMPLE-WIRE-16-4', '16/4 Speaker Wire', 22900, 52000],
        ] as [$code, $name, $cost, $sell]) {
            $product = CatalogProduct::query()->create([
                'organization_id' => $organization->id, 'category_id' => $category->id,
                'base_uom_id' => $foot->id, 'default_sales_uom_id' => $foot->id,
                'product_code' => $code, 'name' => "EXAMPLE — {$name}", 'customer_description' => 'Structured wiring material.',
                'sales_quantity_millis' => 1000, 'default_cost_cents' => $cost, 'default_cost_quantity_millis' => 1000000,
                'default_sell_price_cents' => $sell, 'taxable' => true, 'tracking_type' => 'lot_or_roll', 'active' => true,
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            CatalogProductPurchaseUnit::query()->create([
                'organization_id' => $organization->id, 'catalog_product_id' => $product->id, 'purchase_uom_id' => $box->id,
                'label' => '1,000 ft box', 'base_quantity_millis' => 1000000, 'default_purchase_cost_cents' => $cost,
                'is_default' => true, 'active' => true, 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $products[$code] = $product;
        }

        $package = CatalogPackage::query()->create([
            'organization_id' => $organization->id, 'category_id' => $category->id, 'sales_uom_id' => $location->id,
            'package_code' => 'EXAMPLE-SMART-HOME-ROUGH-IN', 'name' => 'EXAMPLE — Integrated Smart Home TV Rough-In',
            'customer_description' => 'Integrated Smart Home TV rough-in sold per location.',
            'internal_description' => 'Uses a 175-ft standard pull allowance per cable run.',
            'pricing_model' => 'flat', 'default_price_cents' => 189500, 'taxable' => true, 'active' => true,
            'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
        ]);
        foreach ([
            ['EXAMPLE-CAT6-BLUE', 2, 350000], ['EXAMPLE-CAT6-YELLOW', 2, 350000],
            ['EXAMPLE-WIRE-16-2', 1, 175000], ['EXAMPLE-WIRE-16-4', 1, 175000],
        ] as $index => [$code, $pulls, $quantity]) {
            CatalogPackageComponent::query()->create([
                'organization_id' => $organization->id, 'catalog_package_id' => $package->id,
                'component_type' => 'product', 'catalog_product_id' => $products[$code]->id,
                'component_uom_id' => $foot->id, 'quantity_basis' => 'pull_allowance',
                'quantity_millis' => $quantity, 'basis_count_millis' => $pulls * 1000, 'basis_quantity_millis' => 175000,
                'waste_basis_points' => 0, 'customer_visible' => false, 'sort_order' => ($index + 1) * 10,
                'internal_notes' => "{$pulls} pull(s) at 175 ft per location.", 'active' => true,
                'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
        }

        $demand = $this->demand->calculate($package, 5000)['products']->keyBy('product_code');
        $expected = ['EXAMPLE-CAT6-BLUE' => 1750000, 'EXAMPLE-CAT6-YELLOW' => 1750000, 'EXAMPLE-WIRE-16-2' => 875000, 'EXAMPLE-WIRE-16-4' => 875000];
        foreach ($expected as $code => $quantity) {
            if (($demand[$code]['standard_quantity_millis'] ?? null) !== $quantity) {
                throw new RuntimeException("Package demand verification failed for {$code}.");
            }
        }

        return compact('managedService', 'tvService', 'tvVariant', 'package');
    }
}
