<?php

namespace App\Domain;

use App\Models\CatalogCategory;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Organization;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;

class NewDayCatalogBootstrap
{
    public const LABOR_CATEGORY_CODE = 'labor-services';

    public const DISPATCH_CATEGORY_CODE = 'service-dispatch';

    public const LABOR_SERVICES = [
        [
            'service_code' => 'LABOR-RES-IT',
            'name' => 'Residential IT / Computer Support',
            'customer_description' => 'Computer, printer, software, and connected-device setup and troubleshooting for residential customers.',
            'internal_description' => 'Residential computer support, printer support, basic software or device setup, and simple IT service calls.',
            'default_price_cents' => 9500,
        ],
        [
            'service_code' => 'LABOR-RES-TECH',
            'name' => 'Residential Technology Service',
            'customer_description' => 'Residential networking, Wi-Fi, audio/video, smart-home, surveillance, and technology troubleshooting.',
            'internal_description' => 'General residential networking, Wi-Fi, AV, smart-home, surveillance, and technology service.',
            'default_price_cents' => 11500,
        ],
        [
            'service_code' => 'LABOR-BUS',
            'name' => 'Business Service Labor',
            'customer_description' => 'Business technology, network, and systems support performed during a service visit.',
            'internal_description' => 'Commercial break/fix and normal business IT, network, and service support.',
            'default_price_cents' => 13500,
        ],
        [
            'service_code' => 'LABOR-PROJECT',
            'name' => 'Project / Installation Labor',
            'customer_description' => 'Installation, implementation, and project execution labor for an approved scope of work.',
            'internal_description' => 'Installation work, new-project execution, and scoped implementation labor.',
            'default_price_cents' => 14500,
        ],
        [
            'service_code' => 'LABOR-ENG',
            'name' => 'Engineering / Programming',
            'customer_description' => 'Advanced configuration, programming, systems design, and engineering services.',
            'internal_description' => 'Advanced configuration, systems design, programming, and engineering work.',
            'default_price_cents' => 16500,
        ],
    ];

    public const TRIP_SERVICE = [
        'service_code' => 'TRIP',
        'name' => 'Trip / Dispatch Charge',
        'customer_description' => 'Trip / dispatch charge based on en-route travel time to the service location.',
        'internal_description' => 'Catalog-backed dispatch charge selected from reviewed en-route travel duration.',
    ];

    public const TRIP_VARIANTS = [
        [
            'code' => 'TRIP-45-60',
            'label' => '45–60 Minute Travel',
            'customer_label' => '45–60 Minute Travel',
            'price_override_cents' => 4500,
            'sort_order' => 10,
        ],
        [
            'code' => 'TRIP-60-PLUS',
            'label' => '60+ Minute Travel',
            'customer_label' => '60+ Minute Travel',
            'price_override_cents' => 6500,
            'sort_order' => 20,
        ],
    ];

    public function __construct(
        private readonly CatalogDefaults $defaults,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{created: list<string>, unchanged: list<string>} */
    public function ensureLaborServices(Organization $organization, ?User $actor = null): array
    {
        return DB::transaction(function () use ($organization, $actor): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->defaults->ensureFor($organization);

            $category = CatalogCategory::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => self::LABOR_CATEGORY_CODE],
                [
                    'name' => 'Labor Services',
                    'description' => 'Customer-facing hourly labor and professional service offerings.',
                    'sort_order' => 10,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ],
            );
            $hour = UnitOfMeasure::query()
                ->forOrganization($organization->id)
                ->where('code', 'hour')
                ->firstOrFail();
            $result = ['created' => [], 'unchanged' => []];

            foreach (self::LABOR_SERVICES as $definition) {
                $existing = CatalogService::query()
                    ->forOrganization($organization->id)
                    ->where('service_code', $definition['service_code'])
                    ->first();
                if ($existing) {
                    $result['unchanged'][] = $definition['service_code'];

                    continue;
                }

                $service = CatalogService::query()->create($definition + [
                    'organization_id' => $organization->id,
                    'category_id' => $category->id,
                    'sales_uom_id' => $hour->id,
                    'pricing_model' => 'hourly',
                    'taxable' => false,
                    'customer_visible' => true,
                    'requires_office_approval' => false,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ]);
                $result['created'][] = $service->service_code;
                $this->audit->record($organization, $actor, 'catalog.bootstrap_service_created', $service, [
                    'service_code' => $service->service_code,
                    'changed_fields' => ['category_id', 'sales_uom_id', 'service_code', 'name', 'customer_description', 'internal_description', 'pricing_model', 'default_price_cents', 'taxable', 'customer_visible', 'active'],
                ]);
            }

            return $result;
        });
    }

    /** @return array{created: list<string>, unchanged: list<string>} */
    public function ensureTripCharge(Organization $organization, ?User $actor = null): array
    {
        return DB::transaction(function () use ($organization, $actor): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->defaults->ensureFor($organization);
            $category = CatalogCategory::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => self::DISPATCH_CATEGORY_CODE],
                [
                    'name' => 'Service & Dispatch',
                    'description' => 'Service-call and dispatch charges presented to customers.',
                    'sort_order' => 20,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ],
            );
            $visit = UnitOfMeasure::query()
                ->forOrganization($organization->id)
                ->where('code', 'visit')
                ->firstOrFail();
            $result = ['created' => [], 'unchanged' => []];
            $service = CatalogService::query()
                ->forOrganization($organization->id)
                ->where('service_code', self::TRIP_SERVICE['service_code'])
                ->first();

            if (! $service) {
                $service = CatalogService::query()->create(self::TRIP_SERVICE + [
                    'organization_id' => $organization->id,
                    'category_id' => $category->id,
                    'sales_uom_id' => $visit->id,
                    'pricing_model' => 'variant',
                    'default_price_cents' => null,
                    'taxable' => false,
                    'customer_visible' => true,
                    'requires_office_approval' => false,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ]);
                $result['created'][] = self::TRIP_SERVICE['service_code'];
                $this->audit->record($organization, $actor, 'catalog.bootstrap_service_created', $service, [
                    'service_code' => $service->service_code,
                    'changed_fields' => ['category_id', 'sales_uom_id', 'service_code', 'name', 'customer_description', 'internal_description', 'pricing_model', 'taxable', 'customer_visible', 'active'],
                ]);
            } else {
                $result['unchanged'][] = self::TRIP_SERVICE['service_code'];
            }

            foreach (self::TRIP_VARIANTS as $definition) {
                $variant = CatalogServiceVariant::query()
                    ->forOrganization($organization->id)
                    ->where('catalog_service_id', $service->id)
                    ->where('code', $definition['code'])
                    ->first();
                if ($variant) {
                    $result['unchanged'][] = $definition['code'];

                    continue;
                }

                $variant = CatalogServiceVariant::query()->create($definition + [
                    'organization_id' => $organization->id,
                    'catalog_service_id' => $service->id,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ]);
                $result['created'][] = $variant->code;
                $this->audit->record($organization, $actor, 'catalog.bootstrap_variant_created', $variant, [
                    'service_id' => $service->id,
                    'variant_code' => $variant->code,
                    'changed_fields' => ['catalog_service_id', 'code', 'label', 'customer_label', 'price_override_cents', 'sort_order', 'active'],
                ]);
            }

            return $result;
        });
    }
}
