<?php

namespace App\Domain;

use App\Models\BillingLaborRate;
use App\Models\CatalogCategory;
use App\Models\CatalogService;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyLaborCatalogMigrator
{
    public function __construct(
        private readonly CatalogDefaults $defaults,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{
     *   status: 'already_configured'|'no_default_legacy_rate'|'mapped_existing'|'created_and_mapped'|'conflict',
     *   organization_id: int,
     *   legacy_rate_id: ?int,
     *   catalog_service_id: ?int,
     *   catalog_service_code: ?string,
     *   warnings: list<string>
     * }
     */
    public function migrate(Organization $organization, ?User $actor = null): array
    {
        return DB::transaction(function () use ($organization, $actor): array {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $settings = OrganizationBillingSetting::query()->where('organization_id', $organization->id)->lockForUpdate()->first();
            if ($settings?->default_labor_catalog_service_id) {
                return $this->result('already_configured', $organization, null, $settings->default_labor_catalog_service_id);
            }

            $defaultRates = BillingLaborRate::query()
                ->forOrganization($organization->id)
                ->where('active', true)
                ->where('is_default', true)
                ->lockForUpdate()
                ->get();
            if ($defaultRates->isEmpty()) {
                return $this->result('no_default_legacy_rate', $organization);
            }
            if ($defaultRates->count() !== 1) {
                return $this->result('conflict', $organization, warnings: [
                    'Multiple active default legacy labor rates require administrator review.',
                ]);
            }

            $rate = $defaultRates->first();
            $warnings = BillingLaborRate::query()
                ->forOrganization($organization->id)
                ->where('active', true)
                ->whereKeyNot($rate->id)
                ->orderBy('name')
                ->get()
                ->map(fn (BillingLaborRate $other): string => "Legacy rate #{$other->id} ({$other->name}) was not mapped and requires human interpretation if it should be used for new work.")
                ->all();

            $this->defaults->ensureFor($organization);
            $hour = $organization->unitsOfMeasure()->where('code', 'hour')->firstOrFail();
            $stableCode = $this->stableCode($rate);
            $stable = CatalogService::query()->forOrganization($organization->id)->where('service_code', $stableCode)->first();
            if ($stable) {
                if (! $this->isCompatible($stable, $rate, $hour->id)) {
                    return $this->result('conflict', $organization, $rate, $stable, array_merge($warnings, [
                        "Catalog code {$stableCode} already exists but is not an active hourly service with the legacy rate's price and Hour unit.",
                    ]));
                }

                return $this->map($organization, $settings, $rate, $stable, $actor, 'mapped_existing', $warnings);
            }

            $matches = CatalogService::query()
                ->forOrganization($organization->id)
                ->where('active', true)
                ->where('pricing_model', 'hourly')
                ->where('sales_uom_id', $hour->id)
                ->where('default_price_cents', $rate->hourly_rate_cents)
                ->get()
                ->filter(fn (CatalogService $service): bool => $this->normalized($service->name) === $this->normalized($rate->name))
                ->values();
            if ($matches->count() > 1) {
                return $this->result('conflict', $organization, $rate, warnings: array_merge($warnings, [
                    'Multiple Catalog services exactly match the default legacy rate name and price; choose the default labor service manually.',
                ]));
            }
            if ($matches->count() === 1) {
                return $this->map($organization, $settings, $rate, $matches->first(), $actor, 'mapped_existing', $warnings);
            }

            $category = CatalogCategory::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'code' => NewDayCatalogBootstrap::LABOR_CATEGORY_CODE],
                [
                    'name' => 'Labor Services',
                    'description' => 'Customer-facing hourly labor and professional service offerings.',
                    'sort_order' => 10,
                    'active' => true,
                    'created_by_id' => $actor?->id,
                    'updated_by_id' => $actor?->id,
                ],
            );
            $service = CatalogService::query()->create([
                'organization_id' => $organization->id,
                'category_id' => $category->id,
                'sales_uom_id' => $hour->id,
                'service_code' => $stableCode,
                'name' => $rate->name,
                'customer_description' => $rate->name,
                'internal_description' => "Compatibility service created from legacy labor rate #{$rate->id}.",
                'pricing_model' => 'hourly',
                'default_price_cents' => $rate->hourly_rate_cents,
                'taxable' => false,
                'customer_visible' => true,
                'requires_office_approval' => false,
                'active' => true,
                'created_by_id' => $actor?->id,
                'updated_by_id' => $actor?->id,
            ]);
            $this->audit->record($organization, $actor, 'catalog.legacy_labor_service_created', $service, [
                'legacy_labor_rate_id' => $rate->id,
                'catalog_service_id' => $service->id,
                'service_code' => $service->service_code,
                'changed_fields' => ['category_id', 'sales_uom_id', 'service_code', 'name', 'pricing_model', 'default_price_cents', 'taxable', 'customer_visible', 'active'],
            ]);

            return $this->map($organization, $settings, $rate, $service, $actor, 'created_and_mapped', $warnings);
        });
    }

    private function map(Organization $organization, ?OrganizationBillingSetting $settings, BillingLaborRate $rate, CatalogService $service, ?User $actor, string $status, array $warnings): array
    {
        $settings ??= new OrganizationBillingSetting(['organization_id' => $organization->id]);
        $settings->fill([
            'default_currency' => $settings->default_currency ?: 'USD',
            'default_payment_terms' => $settings->default_payment_terms ?: 'due_on_receipt',
            'default_labor_catalog_service_id' => $service->id,
            'updated_by_id' => $actor?->id,
        ])->save();
        $this->audit->record($organization, $actor, 'billing.legacy_labor_mapped', $settings, [
            'legacy_labor_rate_id' => $rate->id,
            'catalog_service_id' => $service->id,
            'catalog_service_code' => $service->service_code,
            'changed_fields' => ['default_labor_catalog_service_id'],
        ]);

        return $this->result($status, $organization, $rate, $service, $warnings);
    }

    private function stableCode(BillingLaborRate $rate): string
    {
        return 'LEGACY-LABOR-'.$rate->id;
    }

    private function normalized(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    private function isCompatible(CatalogService $service, BillingLaborRate $rate, int $hourId): bool
    {
        return $service->active
            && $service->pricing_model === 'hourly'
            && (int) $service->sales_uom_id === $hourId
            && (int) $service->default_price_cents === (int) $rate->hourly_rate_cents;
    }

    /** @return array{status: string, organization_id: int, legacy_rate_id: ?int, catalog_service_id: ?int, catalog_service_code: ?string, warnings: list<string>} */
    private function result(string $status, Organization $organization, ?BillingLaborRate $rate = null, CatalogService|int|null $service = null, array $warnings = []): array
    {
        return [
            'status' => $status,
            'organization_id' => $organization->id,
            'legacy_rate_id' => $rate?->id,
            'catalog_service_id' => $service instanceof CatalogService ? $service->id : $service,
            'catalog_service_code' => $service instanceof CatalogService ? $service->service_code : null,
            'warnings' => $warnings,
        ];
    }
}
