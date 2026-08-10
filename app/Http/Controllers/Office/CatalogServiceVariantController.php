<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogServiceVariantController extends Controller
{
    use ResolvesCatalogRecords;

    public function store(Request $request, string $service, AuditRecorder $audit): RedirectResponse
    {
        $service = $this->service($request, $service);
        Gate::authorize('update', $service);
        $data = $this->validated($request, $service);
        $variant = $service->variants()->create($data + ['organization_id' => $service->organization_id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.service_variant_created', $variant, ['service_id' => $service->id, 'variant_id' => $variant->id, 'changed_fields' => array_keys($data)]);

        return back()->with('status', 'Variant added.');
    }

    public function update(Request $request, string $service, string $variant, AuditRecorder $audit): RedirectResponse
    {
        $service = $this->service($request, $service);
        Gate::authorize('update', $service);
        /** @var CatalogServiceVariant $variant */
        $variant = $this->catalogRecord($request, CatalogServiceVariant::class, $variant, 'catalog_service_variant');
        abort_unless((int) $variant->catalog_service_id === (int) $service->id, 404);
        Gate::authorize('update', $variant);
        $data = $this->validated($request, $service, $variant);
        $changed = collect($data)->filter(fn ($value, $field) => $variant->{$field} != $value)->keys()->all();
        DB::transaction(function () use ($variant, $request, $data): void {
            $variant = CatalogServiceVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $variant->update($data + ['updated_by_id' => $request->user()->id]);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.service_variant_updated', $variant, ['service_id' => $service->id, 'variant_id' => $variant->id, 'changed_fields' => $changed]);

        return back()->with('status', 'Variant saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, CatalogService $service, ?CatalogServiceVariant $variant = null): array
    {
        if ($service->pricing_model !== 'variant') {
            throw ValidationException::withMessages(['variant' => 'Variants can only be managed for a variant-priced service.']);
        }
        $canManagePricing = $request->attributes->get('membership')->hasCapability('catalog.pricing.manage');
        if (! $canManagePricing && $request->has('price_override')) {
            abort(403);
        }
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $rules = [
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('catalog_service_variants')->where('catalog_service_id', $service->id)->ignore($variant?->id)],
            'label' => ['required', 'string', 'max:120'],
            'customer_label' => ['nullable', 'string', 'max:120'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'active' => ['nullable', 'boolean'],
        ];
        if ($canManagePricing) {
            $rules['price_override'] = ['nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'];
        }
        $data = $request->validate($rules);
        $data['active'] = $request->boolean('active');
        if ($canManagePricing) {
            $data['price_override_cents'] = filled($data['price_override'] ?? null) ? $this->dollarsToCents((string) $data['price_override']) : null;
            unset($data['price_override']);
        } else {
            $data['price_override_cents'] = $variant?->price_override_cents;
        }
        if ($data['active'] && $data['price_override_cents'] === null && $service->default_price_cents === null) {
            throw ValidationException::withMessages(['price_override' => 'An active variant needs a price override when the service has no default price.']);
        }

        return $data;
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
