<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialDefaults;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OrganizationCommercialSetting;
use App\Support\AuditRecorder;
use App\Support\FixedPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommercialSettingsController extends Controller
{
    public function edit(Request $request, CommercialDefaults $defaults): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('administer', [Opportunity::class, $organization]);
        $stages = $defaults->ensure($organization);
        $settings = OrganizationCommercialSetting::query()->where('organization_id', $organization->id)->firstOrFail();

        return view('office.settings.commercial', compact('settings', 'stages'));
    }

    public function update(Request $request, AuditRecorder $audit, CommercialDefaults $defaults): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('administer', [Opportunity::class, $organization]);
        $stages = $defaults->ensure($organization);
        $data = $request->validate([
            'default_proposal_expiration_days' => ['required', 'integer', 'between:1,365'], 'gross_margin_floor_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'discount_approval_ceiling_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/'], 'first_reminder_days' => ['required', 'integer', 'between:0,90'],
            'second_reminder_days' => ['required', 'integer', 'between:0,90'], 'notification_policy' => ['required', Rule::in(['staff_only', 'owner_and_staff'])],
            'customer_labor_grouping' => ['nullable', Rule::in(['location', 'system'])],
            'approve_manual_price_overrides' => ['nullable', 'boolean'], 'approve_below_cost_lines' => ['nullable', 'boolean'], 'approve_terms_overrides' => ['nullable', 'boolean'],
            'stages' => ['required', 'array'], 'stages.*.name' => ['required', 'string', 'max:80'], 'stages.*.default_probability_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'stages.*.color' => ['required', Rule::in(['slate', 'blue', 'orange', 'purple', 'green', 'red'])], 'stages.*.sort_order' => ['required', 'integer', 'between:0,1000'],
        ]);
        $data['gross_margin_floor_bps'] = $this->percent($data['gross_margin_floor_percent'], 'gross_margin_floor_percent');
        $data['discount_approval_ceiling_bps'] = $this->percent($data['discount_approval_ceiling_percent'], 'discount_approval_ceiling_percent');
        unset($data['gross_margin_floor_percent'], $data['discount_approval_ceiling_percent']);
        foreach ($data['stages'] as $stageId => &$stageData) {
            $stageData['default_probability_bps'] = $this->percent($stageData['default_probability_percent'], "stages.{$stageId}.default_probability_percent");
            unset($stageData['default_probability_percent']);
        }
        unset($stageData);
        $settings = OrganizationCommercialSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $settings->update([
            ...collect($data)->except('stages')->all(),
            'approve_manual_price_overrides' => $request->boolean('approve_manual_price_overrides'),
            'approve_below_cost_lines' => $request->boolean('approve_below_cost_lines'),
            'approve_terms_overrides' => $request->boolean('approve_terms_overrides'),
            'customer_show_line_details' => $request->boolean('customer_show_line_details'),
            'customer_show_optional_items' => $request->boolean('customer_show_optional_items'),
            'customer_show_location_totals' => $request->boolean('customer_show_location_totals'),
            'customer_show_manufacturer_model' => $request->boolean('customer_show_manufacturer_model'),
            'customer_show_product_images' => $request->boolean('customer_show_product_images'),
            'customer_show_package_components' => $request->boolean('customer_show_package_components'),
        ]);
        foreach ($stages as $stage) {
            $stageData = $data['stages'][$stage->id] ?? null;
            if (! $stageData) {
                continue;
            }
            $stage->update($stageData);
        }
        $audit->record($organization, $request->user(), 'commercial.settings_updated', $organization, ['changed_fields' => array_keys(collect($data)->except('stages')->all()), 'stage_ids' => $stages->pluck('id')->all()]);

        return back()->with('status', 'Commercial settings saved.');
    }

    private function percent(string $value, string $field): int
    {
        $basisPoints = FixedPoint::percentToBasisPoints($value);
        if ($basisPoints > 10000) {
            throw ValidationException::withMessages([$field => 'Percentage may not exceed 100 percent.']);
        }

        return $basisPoints;
    }
}
