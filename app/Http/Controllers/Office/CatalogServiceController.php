<?php

namespace App\Http\Controllers\Office;

use App\Domain\Commercial\QuoteWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogCategory;
use App\Models\CatalogLaborRole;
use App\Models\CatalogService;
use App\Models\UnitOfMeasure;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogServiceController extends Controller
{
    use ResolvesCatalogRecords;

    private const PROTECTED_PRICING_FIELDS = ['pricing_model', 'default_price', 'default_internal_cost', 'default_labor_role_id', 'taxable', 'billing_cadence', 'billing_interval'];

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CatalogService::class, $organization]);
        $services = CatalogService::query()->forOrganization($organization->id)->with(['category', 'salesUom'])->withCount('variants')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%')->orWhere('service_code', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('category'), fn ($query) => $query->where('category_id', $request->integer('category')))
            ->when($request->filled('pricing_model'), fn ($query) => $query->where('pricing_model', $request->string('pricing_model')))
            ->when($request->filled('status'), fn ($query) => $query->where('active', $request->string('status')->value() === 'active'))
            ->orderBy('name')->paginate(25)->withQueryString();
        $categories = CatalogCategory::query()->forOrganization($organization->id)->where('active', true)->orderBy('name')->get();

        return view('office.catalog.services.index', compact('services', 'categories'));
    }

    public function show(Request $request, string $service): View
    {
        $service = $this->service($request, $service);
        Gate::authorize('view', $service);
        $service->load(['category', 'salesUom', 'variants', 'addons.category']);
        $addonCandidates = CatalogService::query()->forOrganization($service->organization_id)->where('active', true)->whereKeyNot($service->id)->orderBy('name')->get();

        return view('office.catalog.services.show', compact('service', 'addonCandidates'));
    }

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogService::class, $organization]);
        [$categories, $units, $laborRoles] = $this->options($organization->id);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.services.create', compact('categories', 'units', 'laborRoles', 'canManagePricing'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogService::class, $organization]);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $organization->id, null, $canManagePricing);
        $service = DB::transaction(function () use ($organization, $request, $data): CatalogService {
            return CatalogService::query()->create($data + ['organization_id' => $organization->id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        });
        $audit->record($organization, $request->user(), 'catalog.service_created', $service, ['service_id' => $service->id, 'pricing_model' => $service->pricing_model, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.services.show', $service)->with('status', 'Service created.');
    }

    public function edit(Request $request, string $service): View
    {
        $service = $this->service($request, $service);
        Gate::authorize('update', $service);
        [$categories, $units, $laborRoles] = $this->options($service->organization_id, true);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');

        return view('office.catalog.services.edit', compact('service', 'categories', 'units', 'laborRoles', 'canManagePricing'));
    }

    public function update(Request $request, string $service, AuditRecorder $audit, QuoteWorkflow $quotes): RedirectResponse
    {
        $service = $this->service($request, $service);
        Gate::authorize('update', $service);
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        $data = $this->validated($request, $service->organization_id, $service, $canManagePricing);
        $changed = collect($data)->filter(fn ($value, $field) => $service->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($service, $request, $data): void {
            $service = CatalogService::query()->lockForUpdate()->findOrFail($service->id);
            $service->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.service_updated', $service, ['service_id' => $service->id, 'changed_fields' => $changed]);
        if (array_intersect($changed, ['default_internal_cost_cents', 'default_labor_role_id', 'estimated_duration_minutes', 'pricing_model'])) {
            $quotes->refreshServiceEstimatingDefaults($service->fresh(), $request->user());
        }

        return redirect()->route('office.catalog.services.show', $service)->with('status', 'Service saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, ?CatalogService $service, bool $canManagePricing): array
    {
        if (! $canManagePricing && $request->hasAny(self::PROTECTED_PRICING_FIELDS)) {
            abort(403);
        }
        $request->merge(['service_code' => strtoupper((string) $request->input('service_code'))]);
        $rules = [
            'service_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_services')->where('organization_id', $organizationId)->ignore($service?->id)],
            'name' => ['required', 'string', 'max:160'],
            'category_id' => ['nullable', 'integer', Rule::exists('catalog_categories', 'id')->where(function ($query) use ($organizationId, $service): void {
                $query->where('organization_id', $organizationId)->where(function ($query) use ($service): void {
                    $query->where('active', true);
                    if ($service?->category_id) {
                        $query->orWhere('id', $service->category_id);
                    }
                });
            })],
            'sales_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')->where(function ($query) use ($organizationId, $service): void {
                $query->where('organization_id', $organizationId)->where(function ($query) use ($service): void {
                    $query->where('active', true);
                    if ($service) {
                        $query->orWhere('id', $service->sales_uom_id);
                    }
                });
            })],
            'customer_description' => ['nullable', 'string', 'max:5000'],
            'internal_description' => ['nullable', 'string', 'max:5000'],
            'internal_scope' => ['nullable', 'string', 'max:5000'],
            'internal_exclusions' => ['nullable', 'string', 'max:5000'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'customer_visible' => ['nullable', 'boolean'],
            'requires_office_approval' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
        if ($canManagePricing) {
            $rules += [
                'pricing_model' => ['required', Rule::in(CatalogService::PRICING_MODELS)],
                'default_price' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
                'default_internal_cost' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
                'default_labor_role_id' => ['nullable', 'integer', Rule::exists('catalog_labor_roles', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('active', true))],
                'taxable' => ['nullable', 'boolean'],
                'billing_cadence' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
                'billing_interval' => ['nullable', 'integer', 'min:1', 'max:120'],
            ];
        }
        $data = $request->validate($rules);
        $data['category_id'] = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $data['customer_visible'] = $request->boolean('customer_visible');
        $data['requires_office_approval'] = $request->boolean('requires_office_approval');
        $data['active'] = $request->boolean('active');
        if ($canManagePricing) {
            $model = $data['pricing_model'];
            $requiresPrice = in_array($model, ['flat', 'hourly', 'per_unit', 'recurring'], true);
            if ($requiresPrice && blank($data['default_price'])) {
                throw ValidationException::withMessages(['default_price' => 'A default price is required for this pricing model.']);
            }
            if ($model === 'quote_required') {
                $data['default_price'] = null;
            }
            if ($model === 'recurring' && (blank($data['billing_cadence']) || blank($data['billing_interval']))) {
                throw ValidationException::withMessages(['billing_cadence' => 'Recurring services require a cadence and interval.']);
            }
            $data['default_price_cents'] = filled($data['default_price']) ? $this->dollarsToCents((string) $data['default_price']) : null;
            $data['default_internal_cost_cents'] = filled($data['default_internal_cost'] ?? null) ? $this->dollarsToCents((string) $data['default_internal_cost']) : null;
            $data['default_labor_role_id'] = filled($data['default_labor_role_id'] ?? null) ? (int) $data['default_labor_role_id'] : null;
            $data['taxable'] = $request->boolean('taxable');
            if ($model !== 'recurring') {
                $data['billing_cadence'] = null;
                $data['billing_interval'] = null;
            }
            unset($data['default_price'], $data['default_internal_cost']);
        } elseif ($service) {
            $data += collect(self::PROTECTED_PRICING_FIELDS)->mapWithKeys(function (string $field) use ($service): array {
                $modelField = match ($field) {
                    'default_price' => 'default_price_cents',
                    'default_internal_cost' => 'default_internal_cost_cents',
                    default => $field,
                };

                return [$modelField => $service->{$modelField}];
            })->all();
        } else {
            $data += ['pricing_model' => 'quote_required', 'default_price_cents' => null, 'default_internal_cost_cents' => null, 'default_labor_role_id' => null, 'taxable' => false, 'billing_cadence' => null, 'billing_interval' => null];
        }

        return $data;
    }

    /** @return array{0: Collection, 1: Collection, 2: Collection} */
    private function options(int $organizationId, bool $includeInactive = false): array
    {
        $categories = CatalogCategory::query()->forOrganization($organizationId)->when(! $includeInactive, fn ($query) => $query->where('active', true))->orderBy('name')->get();
        $units = UnitOfMeasure::query()->forOrganization($organizationId)->when(! $includeInactive, fn ($query) => $query->where('active', true))->orderBy('dimension')->orderBy('name')->get();

        $laborRoles = CatalogLaborRole::query()->forOrganization($organizationId)->when(! $includeInactive, fn ($query) => $query->where('active', true))->orderBy('name')->get();

        return [$categories, $units, $laborRoles];
    }

    private function dollarsToCents(string $value): int
    {
        [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($decimal, 0, 2), 2, '0');
    }

    private function service(Request $request, string $id): CatalogService
    {
        /** @var CatalogService */
        return $this->catalogRecord($request, CatalogService::class, $id, 'catalog_service');
    }
}
