<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ResolvesCatalogRecords;
use App\Models\CatalogCategory;
use App\Support\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogCategoryController extends Controller
{
    use ResolvesCatalogRecords;

    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [CatalogCategory::class, $organization]);
        $categories = CatalogCategory::query()->forOrganization($organization->id)
            ->with('parent')->withCount(['children', 'services', 'products'])
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%')->orWhere('code', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('status'), fn ($query) => $query->where('active', $request->string('status')->value() === 'active'))
            ->orderBy('sort_order')->orderBy('name')->paginate(25)->withQueryString();

        return view('office.catalog.categories.index', compact('categories'));
    }

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogCategory::class, $organization]);
        $parents = CatalogCategory::query()->forOrganization($organization->id)->whereNull('parent_id')->where('active', true)->orderBy('name')->get();

        return view('office.catalog.categories.create', compact('parents'));
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('create', [CatalogCategory::class, $organization]);
        $data = $this->validated($request, $organization->id);
        $category = CatalogCategory::query()->create($data + ['organization_id' => $organization->id, 'created_by_id' => $request->user()->id, 'updated_by_id' => $request->user()->id]);
        $audit->record($organization, $request->user(), 'catalog.category_created', $category, ['category_id' => $category->id, 'changed_fields' => array_keys($data)]);

        return redirect()->route('office.catalog.categories.index')->with('status', 'Category created.');
    }

    public function edit(Request $request, string $category): View
    {
        $category = $this->category($request, $category);
        Gate::authorize('update', $category);
        $parents = CatalogCategory::query()->forOrganization($category->organization_id)->whereNull('parent_id')->where('active', true)->whereKeyNot($category->id)->orderBy('name')->get();

        return view('office.catalog.categories.edit', compact('category', 'parents'));
    }

    public function update(Request $request, string $category, AuditRecorder $audit): RedirectResponse
    {
        $category = $this->category($request, $category);
        Gate::authorize('update', $category);
        $data = $this->validated($request, $category->organization_id, $category);
        if ($category->children()->exists() && $data['parent_id'] !== null) {
            throw ValidationException::withMessages(['parent_id' => 'A category with child categories cannot also have a parent.']);
        }
        $changed = collect($data)->filter(fn ($value, $field) => $category->{$field} != $value)->keys()->all();
        $category->update($data + ['updated_by_id' => $request->user()->id]);
        $audit->record($request->attributes->get('organization'), $request->user(), 'catalog.category_updated', $category, ['category_id' => $category->id, 'changed_fields' => $changed]);

        return redirect()->route('office.catalog.categories.index')->with('status', 'Category saved.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $organizationId, ?CatalogCategory $category = null): array
    {
        $request->merge(['code' => Str::slug((string) ($request->input('code') ?: $request->input('name')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', Rule::unique('catalog_categories')->where('organization_id', $organizationId)->ignore($category?->id)],
            'parent_id' => ['nullable', 'integer', Rule::exists('catalog_categories', 'id')->where('organization_id', $organizationId)->whereNull('parent_id')->where('active', true)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $data['active'] = $request->boolean('active');
        if ($category && $data['parent_id'] === $category->id) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
        }

        return $data;
    }

    private function category(Request $request, string $id): CatalogCategory
    {
        /** @var CatalogCategory */
        return $this->catalogRecord($request, CatalogCategory::class, $id, 'catalog_category');
    }
}
