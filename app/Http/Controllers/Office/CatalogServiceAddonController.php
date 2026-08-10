<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogService;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogServiceAddonController extends Controller
{
    use ResolvesCatalogRecords;

    public function update(Request $request, string $service, AuditRecorder $audit): RedirectResponse
    {
        /** @var CatalogService $service */
        $service = $this->catalogRecord($request, CatalogService::class, $service, 'catalog_service');
        Gate::authorize('update', $service);
        $data = $request->validate([
            'addon_service_ids' => ['nullable', 'array', 'max:100'],
            'addon_service_ids.*' => ['integer', 'distinct', Rule::exists('catalog_services', 'id')->where('organization_id', $service->organization_id)->where('active', true)],
        ]);
        $ids = collect($data['addon_service_ids'] ?? [])->map(fn ($id) => (int) $id)->reject(fn (int $id) => $id === (int) $service->id)->values();
        DB::transaction(function () use ($service, $ids, $request): void {
            $service = CatalogService::query()->lockForUpdate()->findOrFail($service->id);
            $sync = $ids->mapWithKeys(fn (int $id, int $index): array => [$id => ['organization_id' => $service->organization_id, 'sort_order' => $index * 10, 'created_by_id' => $request->user()->id]])->all();
            $service->addons()->sync($sync);
        });
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.service_addons_updated', $service, ['service_id' => $service->id, 'addon_service_ids' => $ids->all(), 'changed_fields' => ['addons']]);

        return back()->with('status', 'Related add-ons saved.');
    }
}
