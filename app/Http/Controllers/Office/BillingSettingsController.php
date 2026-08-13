<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\BillingLaborRate;
use App\Models\CatalogService;
use App\Models\OrganizationBillingSetting;
use App\Models\PaymentProviderConfiguration;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BillingSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership->hasCapability('billing.settings.manage') || $membership->hasCapability('payments.view'), 403);
        $organization = $request->attributes->get('organization');
        $settings = OrganizationBillingSetting::query()->firstOrCreate(['organization_id' => $organization->id], ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
        $rates = BillingLaborRate::query()->forOrganization($organization->id)->orderByDesc('is_default')->orderBy('name')->get();
        $laborServices = CatalogService::query()->forOrganization($organization->id)->where('active', true)->where('pricing_model', 'hourly')->whereNotNull('default_price_cents')->with('salesUom')->orderBy('name')->get()->filter(fn (CatalogService $service): bool => $service->salesUom->code === 'hour')->values();
        $tripServices = CatalogService::query()->forOrganization($organization->id)->where('active', true)->where('pricing_model', 'variant')->with(['salesUom', 'variants' => fn ($query) => $query->where('active', true)])->orderBy('name')->get()->filter(fn (CatalogService $service): bool => $this->isValidTripService($service))->values();
        $providers = collect(['square', 'stripe'])->mapWithKeys(function (string $provider) use ($organization): array {
            $configuration = PaymentProviderConfiguration::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'provider' => $provider],
                ['public_id' => (string) Str::uuid(), 'environment' => $provider === 'square' ? 'sandbox' : 'test', 'connection_method' => 'legacy_credentials'],
            );

            return [$provider => $configuration];
        });

        return view('office.settings.billing', compact('settings', 'rates', 'organization', 'providers', 'laborServices', 'tripServices'));
    }

    public function update(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate(['default_tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100']]);
        $settings = OrganizationBillingSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $taxRate = $this->percentToBasisPoints((string) $data['default_tax_rate_percent']);
        unset($data['default_tax_rate_percent']);
        $settings->fill(['default_currency' => 'USD', 'updated_by_id' => $request->user()->id]);
        $settings->default_tax_rate_basis_points = $taxRate;
        $settings->save();
        $audit->record($organization, $request->user(), 'billing.settings_updated', $settings, ['changed_fields' => ['default_tax_rate_basis_points']]);

        return back()->with('status', 'Billing settings saved.');
    }

    public function invoiceEdit(Request $request): View
    {
        $organization = $request->attributes->get('organization')->loadMissing('currentFullLogo');
        $settings = OrganizationBillingSetting::query()->firstOrCreate(['organization_id' => $organization->id], ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);

        return view('office.settings.invoices', compact('settings', 'organization'));
    }

    public function laborPolicyUpdate(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate([
            'default_labor_catalog_service_id' => ['nullable', 'integer'],
            'labor_billing_increment_minutes' => ['required', 'integer', Rule::in([0, 15, 30, 60])],
            'labor_rounding_rule' => ['required', Rule::in(['up', 'nearest', 'down'])],
            'minimum_billable_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'trip_charge_catalog_service_id' => ['nullable', 'integer'],
            'suggest_trip_charges' => ['nullable', 'boolean'],
            'auto_select_trip_charges' => ['nullable', 'boolean'],
        ]);
        $laborService = filled($data['default_labor_catalog_service_id'] ?? null)
            ? CatalogService::query()->forOrganization($organization->id)->with('salesUom')->find($data['default_labor_catalog_service_id'])
            : null;
        if ($laborService && (! $laborService->active || $laborService->pricing_model !== 'hourly' || $laborService->default_price_cents === null || $laborService->salesUom->code !== 'hour')) {
            throw ValidationException::withMessages(['default_labor_catalog_service_id' => 'Choose an active hourly Catalog Service with an Hour unit and configured price.']);
        }
        if (filled($data['default_labor_catalog_service_id'] ?? null) && ! $laborService) {
            throw ValidationException::withMessages(['default_labor_catalog_service_id' => 'Choose an hourly Catalog Service from this Organization.']);
        }
        $tripService = filled($data['trip_charge_catalog_service_id'] ?? null)
            ? CatalogService::query()->forOrganization($organization->id)->with(['salesUom', 'variants' => fn ($query) => $query->where('active', true)])->find($data['trip_charge_catalog_service_id'])
            : null;
        if ($tripService && ! $this->isValidTripService($tripService)) {
            throw ValidationException::withMessages(['trip_charge_catalog_service_id' => 'Choose an active Trip / Dispatch Catalog Service containing the required priced travel tiers.']);
        }
        if (filled($data['trip_charge_catalog_service_id'] ?? null) && ! $tripService) {
            throw ValidationException::withMessages(['trip_charge_catalog_service_id' => 'Choose a Trip / Dispatch Catalog Service from this Organization.']);
        }

        $values = [
            'default_labor_catalog_service_id' => $laborService?->id,
            'labor_billing_increment_minutes' => (int) $data['labor_billing_increment_minutes'],
            'labor_rounding_rule' => $data['labor_rounding_rule'],
            'minimum_billable_minutes' => (int) $data['minimum_billable_minutes'],
            'trip_charge_catalog_service_id' => $tripService?->id,
            'suggest_trip_charges' => $request->boolean('suggest_trip_charges'),
            'auto_select_trip_charges' => $request->boolean('auto_select_trip_charges'),
            'updated_by_id' => $request->user()->id,
        ];
        if ($values['auto_select_trip_charges'] && ! $values['suggest_trip_charges']) {
            throw ValidationException::withMessages(['auto_select_trip_charges' => 'Automatic selection requires trip-charge suggestions to be enabled.']);
        }
        if (($values['suggest_trip_charges'] || $values['auto_select_trip_charges']) && ! $tripService) {
            throw ValidationException::withMessages(['trip_charge_catalog_service_id' => 'Choose a Trip / Dispatch Catalog Service before enabling suggestions.']);
        }

        $settings = OrganizationBillingSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $changed = collect($values)->except('updated_by_id')->filter(fn ($value, $field): bool => $settings->{$field} != $value)->keys()->values()->all();
        $settings->fill($values + ['default_currency' => 'USD'])->save();
        $audit->record($organization, $request->user(), 'billing.labor_policy_updated', $settings, ['changed_fields' => $changed]);

        return back()->with('status', 'Labor billing policy saved.');
    }

    public function invoiceUpdate(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate(['default_payment_terms' => ['required', Rule::in(['due_on_receipt', 'custom'])]]);
        $settings = OrganizationBillingSetting::query()->firstOrNew(['organization_id' => $organization->id]);
        $settings->fill($data + ['default_currency' => 'USD', 'updated_by_id' => $request->user()->id])->save();
        $audit->record($organization, $request->user(), 'invoice.settings_updated', $settings, ['changed_fields' => ['default_payment_terms', 'default_currency']]);

        return back()->with('status', 'Invoice defaults saved.');
    }

    public function storeRate(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('billing_labor_rates')->where('organization_id', $organization->id)], 'hourly_rate' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'is_default' => ['nullable', 'boolean']]);
        $rate = DB::transaction(function () use ($organization, $request, $data): BillingLaborRate {
            $default = $request->boolean('is_default') || ! BillingLaborRate::query()->forOrganization($organization->id)->where('active', true)->exists();
            if ($default) {
                BillingLaborRate::query()->forOrganization($organization->id)->update(['is_default' => false]);
            }

            return BillingLaborRate::query()->create(['organization_id' => $organization->id, 'name' => $data['name'], 'hourly_rate_cents' => $this->dollarsToCents($data['hourly_rate']), 'is_default' => $default, 'active' => true, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        });
        $audit->record($organization, $request->user(), 'billing.labor_rate_created', $rate, ['changed_fields' => ['name', 'hourly_rate_cents', 'is_default']]);

        return back()->with('status', 'Labor rate added.');
    }

    public function updateRate(Request $request, string $rate, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $rate = BillingLaborRate::query()->forOrganization($organization->id)->findOrFail($rate);
        $data = $request->validate(['hourly_rate' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'active' => ['nullable', 'boolean'], 'is_default' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($rate, $request, $data, $organization): void {
            $default = $request->boolean('is_default');
            $active = $request->boolean('active');
            if ($default) {
                BillingLaborRate::query()->forOrganization($organization->id)->update(['is_default' => false]);
            }
            if ((! $active || ! $default) && $rate->is_default && ! BillingLaborRate::query()->forOrganization($organization->id)->whereKeyNot($rate->id)->where('active', true)->where('is_default', true)->exists()) {
                throw ValidationException::withMessages(['is_default' => 'Keep one active default labor rate.']);
            }
            $rate->update(['hourly_rate_cents' => $this->dollarsToCents($data['hourly_rate']), 'active' => $active, 'is_default' => $default, 'updated_by_id' => $request->user()->id]);
        });
        $audit->record($organization, $request->user(), 'billing.labor_rate_updated', $rate, ['changed_fields' => ['hourly_rate_cents', 'active', 'is_default']]);

        return back()->with('status', 'Labor rate updated.');
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function percentToBasisPoints(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function isValidTripService(CatalogService $service): bool
    {
        if (! $service->active || $service->pricing_model !== 'variant') {
            return false;
        }

        $variants = $service->variants->keyBy('code');

        return collect(['TRIP-45-60', 'TRIP-60-PLUS'])->every(function (string $code) use ($variants): bool {
            $variant = $variants->get($code);

            return $variant && $variant->active && ($variant->price_override_cents !== null || $variant->service?->default_price_cents !== null);
        });
    }
}
