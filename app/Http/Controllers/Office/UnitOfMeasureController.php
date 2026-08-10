<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\UnitOfMeasure;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UnitOfMeasureController extends Controller
{
    use ResolvesCatalogRecords;

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [UnitOfMeasure::class, $organization]);
        $units = UnitOfMeasure::query()->forOrganization($organization->id)->withCount('services')
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('dimension'), fn ($query) => $query->where('dimension', $request->string('dimension')))
            ->when($request->filled('status'), fn ($query) => $query->where('active', $request->string('status')->value() === 'active'))
            ->orderBy('dimension')->orderBy('name')->paginate(25)->withQueryString();

        return view('office.catalog.units.index', compact('units'));
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', [UnitOfMeasure::class, $request->attributes->get('organization')]);

        return view('office.catalog.units.create');
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [UnitOfMeasure::class, $organization]);
        $data = $this->validated($request, $organization->id);
        $unit = UnitOfMeasure::query()->create($data + ['organization_id' => $organization->id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        $audit->record($organization, $request->user(), 'catalog.unit_created', $unit, ['unit_id' => $unit->id, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.units.index')->with('status', 'Unit created.');
    }

    public function edit(Request $request, string $unit): View
    {
        $unit = $this->unit($request, $unit);
        Gate::authorize('update', $unit);

        return view('office.catalog.units.edit', compact('unit'));
    }

    public function update(Request $request, string $unit, AuditRecorder $audit): RedirectResponse
    {
        $unit = $this->unit($request, $unit);
        Gate::authorize('update', $unit);
        $data = $this->validated($request, $unit->organization_id, $unit);
        $changed = collect($data)->filter(fn ($value, $field) => $unit->{$field} != $value)->keys()->all();
        $unit->update($data + ['updated_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.unit_updated', $unit, ['unit_id' => $unit->id, 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.units.index')->with('status', 'Unit saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, ?UnitOfMeasure $unit = null): array
    {
        $request->merge(['code' => Str::slug((string) ($request->input('code') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:40', Rule::unique('units_of_measure')->where('organization_id', $organizationId)->ignore($unit?->id)],
            'symbol' => ['nullable', 'string', 'max:20'],
            'dimension' => ['required', Rule::in(['count', 'length', 'time', 'service', 'period', 'package'])],
            'decimal_places' => ['required', 'integer', 'min:0', 'max:3'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');

        return $data;
    }

    private function unit(Request $request, string $id): UnitOfMeasure
    {
        /** @var UnitOfMeasure */
        return $this->catalogRecord($request, UnitOfMeasure::class, $id, 'unit_of_measure');
    }
}
