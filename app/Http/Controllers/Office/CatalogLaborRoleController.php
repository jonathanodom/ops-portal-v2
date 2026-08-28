<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\QuoteWorkflow;
use App\Http\Controllers\Controller;
use App\Models\CatalogLaborRole;
use App\Models\CatalogService;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CatalogLaborRoleController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        abort_unless($request->attributes->get('membership')->hasCapability('catalog.pricing.manage'), 403);
        $roles = CatalogLaborRole::query()->forOrganization($organization->id)->orderBy('name')->get();

        return view('office.catalog.labor-roles.index', compact('roles'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        abort_unless($request->attributes->get('membership')->hasCapability('catalog.pricing.manage'), 403);
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_labor_roles')->where('organization_id', $organization->id)],
            'name' => ['required', 'string', 'max:160'],
            'hourly_cost' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'active' => ['nullable', 'boolean'],
        ]);
        $role = DB::transaction(fn () => CatalogLaborRole::query()->create([
            'organization_id' => $organization->id, 'code' => $data['code'], 'name' => $data['name'],
            'hourly_cost_cents' => $this->dollarsToCents($data['hourly_cost']), 'active' => $request->boolean('active'),
            'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id,
        ]));
        $audit->record($organization, $request->user(), 'catalog.labor_role_created', $role, ['labor_role_id' => $role->id, 'changed_fields' => ['code', 'name', 'hourly_cost_cents', 'active']]);

        return back()->with('status', 'Labor role created.');
    }

    public function update(Request $request, string $laborRole, AuditRecorder $audit, QuoteWorkflow $quotes): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        abort_unless($request->attributes->get('membership')->hasCapability('catalog.pricing.manage'), 403);
        $role = CatalogLaborRole::query()->forOrganization($organization->id)->findOrFail($laborRole);
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_labor_roles')->where('organization_id', $organization->id)->ignore($role->id)],
            'name' => ['required', 'string', 'max:160'], 'hourly_cost' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'active' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($role, $data, $request): void {
            CatalogLaborRole::query()->whereKey($role->id)->lockForUpdate()->firstOrFail()->update([
                'code' => $data['code'], 'name' => $data['name'], 'hourly_cost_cents' => $this->dollarsToCents($data['hourly_cost']),
                'active' => $request->boolean('active'), 'updated_by_id' => $request->user()->id,
            ]);
        });
        $audit->record($organization, $request->user(), 'catalog.labor_role_updated', $role, ['labor_role_id' => $role->id, 'changed_fields' => ['code', 'name', 'hourly_cost_cents', 'active']]);
        CatalogService::query()->forOrganization($organization->id)->where('default_labor_role_id', $role->id)->eachById(fn (CatalogService $service) => $quotes->refreshServiceEstimatingDefaults($service, $request->user()));

        return back()->with('status', 'Labor role saved.');
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }
}
