<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\BillingLaborRate;
use App\Models\OrganizationBillingSetting;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BillingSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        $settings = OrganizationBillingSetting::query()->firstOrCreate(['organization_id' => $organization->id], ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt']);
        $rates = BillingLaborRate::query()->forOrganization($organization->id)->orderByDesc('is_default')->orderBy('name')->get();

        return view('office.settings.billing', compact('settings', 'rates', 'organization'));
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
}
