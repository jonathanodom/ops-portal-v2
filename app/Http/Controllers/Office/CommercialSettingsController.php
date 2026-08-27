<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\CommercialDefaults;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OrganizationCommercialSetting;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'default_proposal_expiration_days' => ['required', 'integer', 'between:1,365'], 'gross_margin_floor_bps' => ['required', 'integer', 'between:0,10000'],
            'discount_approval_ceiling_bps' => ['required', 'integer', 'between:0,10000'], 'first_reminder_days' => ['required', 'integer', 'between:0,90'],
            'second_reminder_days' => ['required', 'integer', 'between:0,90'], 'notification_policy' => ['required', Rule::in(['staff_only', 'owner_and_staff'])],
            'approve_manual_price_overrides' => ['nullable', 'boolean'], 'approve_below_cost_lines' => ['nullable', 'boolean'], 'approve_terms_overrides' => ['nullable', 'boolean'],
            'stages' => ['required', 'array'], 'stages.*.name' => ['required', 'string', 'max:80'], 'stages.*.default_probability_bps' => ['required', 'integer', 'between:0,10000'],
            'stages.*.color' => ['required', Rule::in(['slate', 'blue', 'orange', 'purple', 'green', 'red'])], 'stages.*.sort_order' => ['required', 'integer', 'between:0,1000'],
        ]);
        $settings = OrganizationCommercialSetting::query()->where('organization_id', $organization->id)->firstOrFail();
        $settings->update([
            ...collect($data)->except('stages')->all(),
            'approve_manual_price_overrides' => $request->boolean('approve_manual_price_overrides'),
            'approve_below_cost_lines' => $request->boolean('approve_below_cost_lines'),
            'approve_terms_overrides' => $request->boolean('approve_terms_overrides'),
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
}
