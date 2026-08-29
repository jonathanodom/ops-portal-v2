<?php

namespace App\Domain\Commercial;

use App\Domain\CatalogLineSnapshotFactory;
use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CommercialContentBlock;
use App\Models\CommercialDocument;
use App\Models\CommercialPhase;
use App\Models\CommercialRevision;
use App\Models\CommercialRevisionLine;
use App\Models\CommercialRevisionLineComponent;
use App\Models\CommercialSystem;
use App\Models\CommercialTermsSet;
use App\Models\Opportunity;
use App\Models\OrganizationBillingSetting;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class QuoteWorkflow
{
    public function __construct(
        private readonly QuoteNumber $numbers,
        private readonly CatalogLineSnapshotFactory $snapshots,
        private readonly CommercialCalculator $calculator,
        private readonly ServiceEstimateCostResolver $serviceCosts,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(Opportunity $opportunity, User $actor, string $title): CommercialDocument
    {
        return DB::transaction(function () use ($opportunity, $actor, $title): CommercialDocument {
            $opportunity = Opportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            $number = $this->numbers->next($opportunity->organization);
            $document = CommercialDocument::query()->create([
                'organization_id' => $opportunity->organization_id, 'document_type' => 'quote',
                'document_number' => $number, 'opportunity_id' => $opportunity->id,
                'title' => $title, 'status' => 'draft', 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $taxRate = (int) (OrganizationBillingSetting::query()->where('organization_id', $opportunity->organization_id)->value('default_tax_rate_basis_points') ?? 0);
            $revision = CommercialRevision::query()->create([
                'organization_id' => $opportunity->organization_id, 'commercial_document_id' => $document->id,
                'version' => 1, 'status' => 'draft', 'currency' => 'USD', 'tax_rate_basis_points' => $taxRate,
                'customer_tax_exempt' => (bool) $opportunity->customer->tax_exempt,
                'tax_exemption_reference' => $opportunity->customer->tax_exemption_reference,
                'content_hash' => str_repeat('0', 64), 'created_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ]);
            $revision->locations()->create(['organization_id' => $opportunity->organization_id, 'name' => $opportunity->serviceLocation?->name ?? 'Customer scope', 'sort_order' => 10]);
            foreach (CommercialSystem::query()->where('organization_id', $opportunity->organization_id)->where('active', true)->orderBy('sort_order')->get() as $system) {
                $revision->systems()->create(['organization_id' => $opportunity->organization_id, 'source_default_id' => $system->id, 'name' => $system->name, 'sort_order' => $system->sort_order]);
            }
            foreach (CommercialPhase::query()->where('organization_id', $opportunity->organization_id)->where('active', true)->orderBy('sort_order')->get() as $phase) {
                $revision->phases()->create(['organization_id' => $opportunity->organization_id, 'source_default_id' => $phase->id, 'name' => $phase->name, 'sort_order' => $phase->sort_order]);
            }
            $this->refresh($revision, $actor, false);
            $this->audit->record($opportunity->organization, $actor, 'quote.created', $opportunity, ['quote_id' => $document->id, 'revision_id' => $revision->id, 'document_number' => $number]);

            return $document->fresh('revisions');
        });
    }

    /** @param array<string,mixed> $data */
    public function updateRevision(CommercialRevision $revision, User $actor, array $data): CommercialRevision
    {
        return $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($data): void {
            $override = (int) $data['tax_rate_basis_points'] !== (int) $locked->tax_rate_basis_points;
            if ($override && blank($data['tax_override_reason'] ?? null)) {
                throw ValidationException::withMessages(['tax_override_reason' => 'A reason is required to override tax.']);
            }
            $locked->fill([
                'discount_type' => $data['discount_type'] ?: null, 'discount_value' => (int) ($data['discount_value'] ?? 0),
                'tax_rate_basis_points' => (int) $data['tax_rate_basis_points'],
                'tax_rate_overridden' => $override || $locked->tax_rate_overridden,
                'tax_override_reason' => $override ? $data['tax_override_reason'] : $locked->tax_override_reason,
            ])->save();
        }, 'quote.revision_updated', ['changed_fields' => ['discount_type', 'discount_value', 'tax_rate_basis_points']]);
    }

    public function refreshServiceEstimatingDefaults(CatalogService $service, User $actor): int
    {
        $service->loadMissing('defaultLaborRole');
        $resolved = $this->serviceCosts->resolve($service);
        $revisionIds = CommercialRevision::query()->where('organization_id', $service->organization_id)->where('status', 'draft')
            ->where(function ($query) use ($service): void {
                $query->whereHas('lines', fn ($lines) => $lines->where('catalog_service_id', $service->id))
                    ->orWhereHas('lines.components', fn ($components) => $components->where('catalog_service_id', $service->id));
            })->pluck('id');

        foreach ($revisionIds as $revisionId) {
            DB::transaction(function () use ($revisionId, $service, $resolved, $actor): void {
                $revision = CommercialRevision::query()->whereKey($revisionId)->where('status', 'draft')->lockForUpdate()->first();
                if (! $revision) {
                    return;
                }
                $values = [
                    'cost_basis_cents' => $resolved['cost_cents'], 'cost_basis_quantity_millis' => $resolved['basis_quantity_millis'],
                    'cost_resolved' => $resolved['cost_cents'] !== null, 'cost_source_type' => $resolved['source_type'],
                    'cost_source_id' => $resolved['source_id'], 'cost_source_name' => $resolved['source_name'],
                ];
                $revision->lines()->where('catalog_service_id', $service->id)->update($values);
                CommercialRevisionLineComponent::query()->whereIn('commercial_revision_line_id', $revision->lines()->select('id'))
                    ->where('catalog_service_id', $service->id)->update($values);
                $this->refresh($revision, $actor);
                $this->audit->record($revision->document->opportunity->organization, $actor, 'quote.service_cost_refreshed', $revision->document->opportunity, [
                    'quote_id' => $revision->commercial_document_id, 'revision_id' => $revision->id, 'service_id' => $service->id,
                    'changed_fields' => ['cost_basis_cents', 'cost_basis_quantity_millis', 'cost_source'],
                ]);
            });
        }

        return $revisionIds->count();
    }

    /** @param array<string,mixed> $data */
    public function addCatalogLine(CommercialRevision $revision, User $actor, array $data): CommercialRevisionLine
    {
        $created = null;
        $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($data, &$created): void {
            $snapshot = $this->snapshots->create($locked->organization_id, $data['catalog_item_type'], (int) $data['catalog_item_id'], (int) $data['quantity_millis'], $data['catalog_service_variant_id'] ?? null);
            if ($snapshot['catalog_unit_price_cents'] === null) {
                throw ValidationException::withMessages(['catalog_item_id' => 'This Catalog item needs a sell price before it can be quoted.']);
            }
            [$categoryId, $costCents, $costQuantity, $costSourceType, $costSourceId, $costSourceName] = $this->sourceCost($locked->organization_id, $snapshot);
            $created = $locked->lines()->create([
                'organization_id' => $locked->organization_id,
                'location_id' => $this->dimension($locked, 'locations', $data['location_id'] ?? null),
                'system_id' => $this->dimension($locked, 'systems', $data['system_id'] ?? null),
                'phase_id' => $this->dimension($locked, 'phases', $data['phase_id'] ?? null),
                'catalog_category_id' => $categoryId, 'line_type' => $snapshot['catalog_item_type'],
                'catalog_product_id' => $snapshot['catalog_product_id'] ?? null, 'catalog_service_id' => $snapshot['catalog_service_id'] ?? null,
                'catalog_service_variant_id' => $snapshot['catalog_service_variant_id'] ?? null, 'catalog_package_id' => $snapshot['catalog_package_id'] ?? null,
                'package_pricing_mode' => $snapshot['catalog_package_pricing_model'] ?? null,
                'source_code' => $snapshot['catalog_code_snapshot'], 'description' => $snapshot['catalog_name_snapshot'],
                'customer_description' => $snapshot['catalog_description_snapshot'], 'unit_code' => $snapshot['catalog_unit_code_snapshot'],
                'unit_name' => $snapshot['catalog_unit_name_snapshot'], 'quantity_millis' => $snapshot['catalog_quantity_millis'],
                'catalog_unit_sell_cents' => $snapshot['catalog_unit_price_cents'], 'effective_unit_sell_cents' => $snapshot['catalog_unit_price_cents'],
                'cost_basis_cents' => $costCents, 'cost_basis_quantity_millis' => $costQuantity, 'cost_resolved' => $costCents !== null,
                'cost_source_type' => $costSourceType, 'cost_source_id' => $costSourceId, 'cost_source_name' => $costSourceName,
                'optional' => (bool) ($data['optional'] ?? false), 'included' => ! (bool) ($data['optional'] ?? false),
                'taxable' => $snapshot['catalog_taxable'], 'sort_order' => ((int) $locked->lines()->max('sort_order')) + 10,
            ]);
            if ($created->line_type === 'package') {
                $this->snapshotPackageComponents($created);
            }
        }, 'quote.line_added', ['line_type' => $data['catalog_item_type']]);

        return $created->fresh('components');
    }

    /** @param array<string,mixed> $data */
    public function addAllowance(CommercialRevision $revision, User $actor, array $data): CommercialRevisionLine
    {
        $created = null;
        $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($data, &$created): void {
            $created = $locked->lines()->create([
                'organization_id' => $locked->organization_id, 'line_type' => 'allowance',
                'location_id' => $this->dimension($locked, 'locations', $data['location_id'] ?? null),
                'system_id' => $this->dimension($locked, 'systems', $data['system_id'] ?? null),
                'phase_id' => $this->dimension($locked, 'phases', $data['phase_id'] ?? null),
                'description' => $data['description'], 'customer_description' => $data['customer_description'] ?? null,
                'unit_code' => 'allowance', 'unit_name' => 'Allowance', 'quantity_millis' => 1000,
                'effective_unit_sell_cents' => (int) $data['amount_cents'], 'cost_resolved' => false,
                'optional' => (bool) ($data['optional'] ?? false), 'included' => ! (bool) ($data['optional'] ?? false),
                'taxable' => (bool) ($data['taxable'] ?? false), 'sort_order' => ((int) $locked->lines()->max('sort_order')) + 10,
            ]);
        }, 'quote.allowance_added', ['line_type' => 'allowance']);

        return $created->fresh();
    }

    /** @param array<string,mixed> $data */
    public function updateLine(CommercialRevision $revision, CommercialRevisionLine $line, User $actor, array $data): CommercialRevisionLine
    {
        $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($line, $data): void {
            $line = $locked->lines()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $newPrice = $this->resolveSellPrice($line, $data);
            $line->update([
                'description' => $data['description'], 'customer_description' => $data['customer_description'] ?? null,
                'change_effect' => $locked->document->document_type === 'change_order' ? ($data['change_effect'] ?? 'add') : 'add',
                'substitution_group' => $locked->document->document_type === 'change_order' ? ($data['substitution_group'] ?? null) : null,
                'quantity_millis' => (int) $data['quantity_millis'], 'effective_unit_sell_cents' => $newPrice,
                'sell_price_overridden' => $line->catalog_unit_sell_cents !== null && $newPrice !== (int) $line->catalog_unit_sell_cents,
                'discount_type' => $data['discount_type'] ?: null, 'discount_value' => (int) ($data['discount_value'] ?? 0),
                'optional' => (bool) ($data['optional'] ?? false), 'included' => (bool) ($data['included'] ?? false),
                'taxable' => (bool) ($data['taxable'] ?? false),
                'location_id' => $this->dimension($locked, 'locations', $data['location_id'] ?? null),
                'system_id' => $this->dimension($locked, 'systems', $data['system_id'] ?? null),
                'phase_id' => $this->dimension($locked, 'phases', $data['phase_id'] ?? null),
            ]);
        }, 'quote.line_updated', ['line_id' => $line->id, 'changed_fields' => ['description', 'quantity_millis', 'effective_unit_sell_cents', 'discount', 'optional', 'taxable', 'dimensions']]);

        return $line->fresh();
    }

    public function removeLine(CommercialRevision $revision, CommercialRevisionLine $line, User $actor, int $contentVersion): CommercialRevision
    {
        return $this->mutate($revision, $actor, $contentVersion, fn (CommercialRevision $locked) => $locked->lines()->whereKey($line->id)->firstOrFail()->delete(), 'quote.line_removed', ['line_id' => $line->id]);
    }

    public function copyLine(CommercialRevision $revision, CommercialRevisionLine $line, User $actor, int $contentVersion): CommercialRevisionLine
    {
        $copy = null;
        $this->mutate($revision, $actor, $contentVersion, function (CommercialRevision $locked) use ($line, &$copy): void {
            $source = $locked->lines()->with('components')->whereKey($line->id)->firstOrFail();
            $attributes = $source->only($source->getFillable());
            unset($attributes['commercial_revision_id']);
            $attributes['sort_order'] = ((int) $locked->lines()->max('sort_order')) + 10;
            $copy = $locked->lines()->create($attributes);
            foreach ($source->components as $component) {
                $copy->components()->create($component->only($component->getFillable()));
            }
        }, 'quote.line_copied', ['source_line_id' => $line->id]);

        return $copy->fresh('components');
    }

    /** @param array<string,mixed> $data */
    public function bulkAssign(CommercialRevision $revision, User $actor, array $data): CommercialRevision
    {
        return $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($data): void {
            $ids = collect($data['line_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            $lines = $locked->lines()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($lines->count() !== $ids->count()) {
                throw ValidationException::withMessages(['line_ids' => 'One or more selected lines do not belong to this revision.']);
            }
            $changes = [];
            foreach (['location_id' => 'locations', 'system_id' => 'systems', 'phase_id' => 'phases'] as $field => $relation) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $this->dimension($locked, $relation, $data[$field]);
                }
            }
            foreach ($lines as $line) {
                $line->update($changes);
            }
        }, 'quote.lines_bulk_assigned', ['line_ids' => array_map('intval', $data['line_ids']), 'changed_fields' => ['dimensions']]);
    }

    /** @param array<int|string,string> $effects @param array<int|string,string|null> $groups */
    public function updateChangeEffects(CommercialRevision $revision, User $actor, int $contentVersion, array $effects, array $groups): CommercialRevision
    {
        return $this->mutate($revision, $actor, $contentVersion, function (CommercialRevision $locked) use ($effects, $groups): void {
            if ($locked->document->document_type !== 'change_order') {
                throw ValidationException::withMessages(['change_effects' => 'Change effects apply only to Change Orders.']);
            }
            $lines = $locked->lines()->whereIn('id', array_map('intval', array_keys($effects)))->lockForUpdate()->get();
            if ($lines->count() !== count($effects)) {
                throw ValidationException::withMessages(['change_effects' => 'One or more lines do not belong to this Change Order.']);
            }
            foreach ($lines as $line) {
                $effect = $effects[$line->id] ?? 'add';
                $group = trim((string) ($groups[$line->id] ?? '')) ?: null;
                if (str_starts_with($effect, 'substitute_') && $group === null) {
                    throw ValidationException::withMessages(["substitution_groups.{$line->id}" => 'Substitution lines require a shared group label.']);
                }
                $line->update(['change_effect' => $effect, 'substitution_group' => $group]);
            }
        }, 'change_order.effects_updated', ['line_ids' => array_map('intval', array_keys($effects)), 'changed_fields' => ['change_effect', 'substitution_group']]);
    }

    /** @param array<string,mixed> $data */
    public function updateComponent(CommercialRevision $revision, CommercialRevisionLine $line, CommercialRevisionLineComponent $component, User $actor, array $data): CommercialRevisionLineComponent
    {
        $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($line, $component, $data): void {
            $parent = $locked->lines()->whereKey($line->id)->firstOrFail();
            if ($parent->line_type !== 'package') {
                abort(404);
            }
            $parent->components()->whereKey($component->id)->firstOrFail()->update(['name' => $data['name'], 'quantity_millis' => (int) $data['quantity_millis'], 'waste_basis_points' => (int) $data['waste_basis_points'], 'customer_visible' => (bool) ($data['customer_visible'] ?? false)]);
        }, 'quote.package_component_updated', ['line_id' => $line->id, 'component_id' => $component->id, 'changed_fields' => ['name', 'quantity_millis', 'waste_basis_points', 'customer_visible']]);

        return $component->fresh();
    }

    /** @param array<string,mixed> $data */
    public function addDimension(CommercialRevision $revision, User $actor, string $type, array $data): CommercialRevision
    {
        if (! in_array($type, ['locations', 'systems', 'phases'], true)) {
            abort(404);
        }

        return $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($type, $data): void {
            $attributes = ['organization_id' => $locked->organization_id, 'name' => $data['name'], 'sort_order' => ((int) $locked->{$type}()->max('sort_order')) + 10];
            if ($type === 'locations') {
                $attributes['parent_id'] = $this->dimension($locked, 'locations', $data['parent_id'] ?? null);
            }
            $locked->{$type}()->create($attributes);
        }, 'quote.dimension_added', ['dimension_type' => $type]);
    }

    /** @param array<string,mixed> $data */
    public function addSection(CommercialRevision $revision, User $actor, array $data): CommercialRevision
    {
        return $this->mutate($revision, $actor, (int) $data['content_version'], fn (CommercialRevision $locked) => $locked->sections()->create(['organization_id' => $locked->organization_id, 'heading' => $data['heading'], 'body' => $data['body'] ?? null, 'customer_visible' => (bool) ($data['customer_visible'] ?? false), 'sort_order' => ((int) $locked->sections()->max('sort_order')) + 10]), 'quote.section_added');
    }

    public function addContentBlock(CommercialRevision $revision, CommercialContentBlock $block, User $actor, int $contentVersion): CommercialRevision
    {
        abort_unless($block->organization_id === $revision->organization_id && $block->active, 404);

        return $this->mutate($revision, $actor, $contentVersion, fn (CommercialRevision $locked) => $locked->sections()->create(['organization_id' => $locked->organization_id, 'source_content_block_id' => $block->id, 'heading' => $block->heading, 'body' => $block->body, 'customer_visible' => true, 'sort_order' => ((int) $locked->sections()->max('sort_order')) + 10]), 'quote.content_block_added', ['content_block_id' => $block->id]);
    }

    public function updateTerms(CommercialRevision $revision, ?CommercialTermsSet $terms, User $actor, int $contentVersion, ?string $overrideBody): CommercialRevision
    {
        if ($terms) {
            abort_unless($terms->organization_id === $revision->organization_id && $terms->active, 404);
        }

        return $this->mutate($revision, $actor, $contentVersion, function (CommercialRevision $locked) use ($terms, $overrideBody): void {
            $body = filled($overrideBody) ? $overrideBody : $terms?->body;
            $locked->update(['commercial_terms_set_id' => $terms?->id, 'terms_name_snapshot' => $terms?->name ?? 'Custom terms', 'terms_version_snapshot' => $terms?->version, 'terms_body_snapshot' => $body, 'terms_overridden' => filled($overrideBody) && $overrideBody !== $terms?->body]);
        }, 'quote.terms_updated', ['changed_fields' => ['terms_set', 'terms_body', 'terms_overridden'], 'terms_set_id' => $terms?->id]);
    }

    public function refreshContent(CommercialRevision $revision, User $actor): CommercialRevision
    {
        return DB::transaction(function () use ($revision, $actor): CommercialRevision {
            $revision = CommercialRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if (! $revision->isEditable()) {
                throw ValidationException::withMessages(['revision' => 'Only a Draft revision may be edited.']);
            }
            $this->refresh($revision, $actor);

            return $revision->fresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function addMilestone(CommercialRevision $revision, User $actor, array $data): CommercialRevision
    {
        return $this->mutate($revision, $actor, (int) $data['content_version'], function (CommercialRevision $locked) use ($data): void {
            if (($data['amount_type'] ?? null) === 'percent' && (int) $data['amount_value'] > 10000) {
                throw ValidationException::withMessages(['amount_value' => 'A percentage cannot exceed 100%.']);
            }
            if (($data['is_balancing'] ?? false)) {
                $locked->paymentMilestones()->update(['is_balancing' => false]);
            }
            $locked->paymentMilestones()->create(['organization_id' => $locked->organization_id, 'name' => $data['name'], 'amount_type' => $data['amount_type'], 'amount_value' => (int) $data['amount_value'], 'is_balancing' => (bool) ($data['is_balancing'] ?? false), 'sort_order' => ((int) $locked->paymentMilestones()->max('sort_order')) + 10]);
        }, 'quote.payment_milestone_added');
    }

    public function lockForHistory(CommercialRevision $revision, User $actor, int $contentVersion): CommercialRevision
    {
        return $this->mutate($revision, $actor, $contentVersion, fn (CommercialRevision $locked) => $locked->update(['status' => 'approved', 'locked_at' => now()]), 'quote.revision_locked');
    }

    public function cloneDraft(CommercialRevision $revision, ?User $actor): CommercialRevision
    {
        $copiedObjects = [];
        try {
            return DB::transaction(function () use ($revision, $actor, &$copiedObjects): CommercialRevision {
                $source = CommercialRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
                if ($source->status === 'draft') {
                    throw ValidationException::withMessages(['revision' => 'Lock the current Draft before cloning a new revision.']);
                }
                $document = CommercialDocument::query()->whereKey($source->commercial_document_id)->lockForUpdate()->firstOrFail();
                $version = ((int) $document->revisions()->max('version')) + 1;
                $target = CommercialRevision::query()->create(array_merge($source->only(['organization_id', 'commercial_document_id', 'currency', 'commercial_terms_set_id', 'terms_name_snapshot', 'terms_version_snapshot', 'terms_body_snapshot', 'terms_overridden', 'discount_type', 'discount_value', 'tax_rate_basis_points', 'tax_rate_overridden', 'tax_override_reason', 'customer_tax_exempt', 'tax_exemption_reference']), ['version' => $version, 'source_revision_id' => $source->id, 'status' => 'draft', 'content_version' => 1, 'content_hash' => str_repeat('0', 64), 'created_by_id' => $actor?->id, 'updated_by_id' => $actor?->id]));
                $maps = [];
                foreach (['locations', 'systems', 'phases'] as $relation) {
                    $maps[$relation] = [];
                    foreach ($source->{$relation}()->orderBy('id')->get() as $record) {
                        $data = $record->only(['organization_id', 'source_default_id', 'name', 'sort_order']);
                        if ($relation === 'locations') {
                            unset($data['source_default_id']);
                            $data['parent_id'] = $record->parent_id ? ($maps[$relation][$record->parent_id] ?? null) : null;
                        }
                        $copy = $target->{$relation}()->create($data);
                        $maps[$relation][$record->id] = $copy->id;
                    }
                }
                foreach ($source->sections()->get() as $record) {
                    $target->sections()->create($record->only(['organization_id', 'source_content_block_id', 'heading', 'body', 'customer_visible', 'sort_order']));
                }
                foreach ($source->lines()->with('components')->get() as $line) {
                    $data = $line->only($line->getFillable());
                    unset($data['commercial_revision_id']);
                    $data['location_id'] = $line->location_id ? ($maps['locations'][$line->location_id] ?? null) : null;
                    $data['system_id'] = $line->system_id ? ($maps['systems'][$line->system_id] ?? null) : null;
                    $data['phase_id'] = $line->phase_id ? ($maps['phases'][$line->phase_id] ?? null) : null;
                    $copy = $target->lines()->create($data);
                    foreach ($line->components as $component) {
                        $copy->components()->create($component->only($component->getFillable()));
                    }
                }
                foreach ($source->paymentMilestones()->get() as $record) {
                    $target->paymentMilestones()->create($record->only(['organization_id', 'name', 'amount_type', 'amount_value', 'allocated_cents', 'is_balancing', 'sort_order']));
                }
                foreach ($source->media()->where('state', 'stored')->get() as $record) {
                    $data = $record->only(['organization_id', 'media_type', 'storage_disk', 'storage_key', 'original_name', 'mime_type', 'byte_size', 'sha256', 'embed_url', 'caption', 'state']);
                    if ($record->storage_key) {
                        $extension = pathinfo($record->storage_key, PATHINFO_EXTENSION);
                        $newKey = 'commercial/proposals/'.now()->format('Y/m').'/'.Str::uuid().($extension ? '.'.$extension : '');
                        if (! Storage::disk($record->storage_disk)->copy($record->storage_key, $newKey)) {
                            throw ValidationException::withMessages(['media' => 'Proposal media could not be copied into the new revision.']);
                        }
                        $data['storage_key'] = $newKey;
                        $copiedObjects[] = [$record->storage_disk, $newKey];
                    }
                    $data['uploaded_by_id'] = $actor?->id;
                    $target->media()->create($data);
                }
                $this->refresh($target, $actor, false);
                $document->update(['status' => 'draft', 'updated_by_id' => $actor?->id]);
                $subject = $document->auditSubject();
                $this->audit->record($subject->organization, $actor, $document->document_type === 'change_order' ? 'change_order.revision_cloned' : 'quote.revision_cloned', $subject, ['commercial_document_id' => $document->id, 'source_revision_id' => $source->id, 'revision_id' => $target->id, 'version' => $version]);

                return $target->fresh();
            });
        } catch (Throwable $exception) {
            foreach ($copiedObjects as [$disk, $key]) {
                Storage::disk($disk)->delete($key);
            }
            throw $exception;
        }
    }

    private function mutate(CommercialRevision $revision, User $actor, int $expectedVersion, callable $callback, string $event, array $metadata = []): CommercialRevision
    {
        return DB::transaction(function () use ($revision, $actor, $expectedVersion, $callback, $event, $metadata): CommercialRevision {
            $locked = CommercialRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['revision' => 'Only a Draft revision may be edited.']);
            }
            if ($locked->content_version !== $expectedVersion) {
                throw ValidationException::withMessages(['content_version' => 'This Quote changed in another session. Reload before saving.'])->status(409);
            }
            $callback($locked);
            $this->refresh($locked, $actor);
            $subject = $locked->document->auditSubject();
            $event = $locked->document->document_type === 'change_order' ? str_replace('quote.', 'change_order.', $event) : $event;
            $this->audit->record($subject->organization, $actor, $event, $subject, ['commercial_document_id' => $locked->commercial_document_id, 'revision_id' => $locked->id] + $metadata);

            return $locked->fresh();
        });
    }

    public function refresh(CommercialRevision $revision, ?User $actor, bool $increment = true): void
    {
        $this->calculator->recalculate($revision);
        $version = $increment ? $revision->content_version + 1 : $revision->content_version;
        $payload = [
            'revision' => $revision->only(['version', 'status', 'currency', 'commercial_terms_set_id', 'terms_name_snapshot', 'terms_version_snapshot', 'terms_body_snapshot', 'terms_overridden', 'discount_type', 'discount_value', 'tax_rate_basis_points', 'customer_tax_exempt']),
            'locations' => $revision->locations()->orderBy('id')->get()->toArray(), 'systems' => $revision->systems()->orderBy('id')->get()->toArray(),
            'phases' => $revision->phases()->orderBy('id')->get()->toArray(), 'sections' => $revision->sections()->orderBy('id')->get()->toArray(),
            'lines' => $revision->lines()->with('components')->orderBy('id')->get()->toArray(), 'milestones' => $revision->paymentMilestones()->orderBy('id')->get()->toArray(),
            'media' => $revision->media()->where('state', 'stored')->orderBy('id')->get(['id', 'media_type', 'original_name', 'mime_type', 'byte_size', 'sha256', 'embed_url', 'caption'])->toArray(),
        ];
        $revision->forceFill(['content_version' => $version, 'content_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'updated_by_id' => $actor?->id])->save();
    }

    private function dimension(CommercialRevision $revision, string $relation, mixed $id): ?int
    {
        if (! $id) {
            return null;
        }

        return (int) $revision->{$relation}()->whereKey((int) $id)->value('id') ?: throw ValidationException::withMessages([$relation => 'Choose a dimension from this Quote revision.']);
    }

    /** @param array<string,mixed> $snapshot @return array{?int,?int,?int,?string,?int,?string} */
    private function sourceCost(int $organizationId, array $snapshot): array
    {
        if ($snapshot['catalog_item_type'] === 'product') {
            $item = CatalogProduct::query()->where('organization_id', $organizationId)->findOrFail($snapshot['catalog_product_id']);

            return [$item->category_id, $item->default_cost_cents, $item->default_cost_cents === null ? null : $item->default_cost_quantity_millis, $item->default_cost_cents === null ? null : 'product_default', $item->default_cost_cents === null ? null : $item->id, $item->default_cost_cents === null ? null : $item->name];
        }
        if ($snapshot['catalog_item_type'] === 'service') {
            $item = CatalogService::query()->where('organization_id', $organizationId)->findOrFail($snapshot['catalog_service_id']);

            $resolved = $this->serviceCosts->resolve($item->loadMissing('defaultLaborRole'));

            return [$item->category_id, $resolved['cost_cents'], $resolved['basis_quantity_millis'], $resolved['source_type'], $resolved['source_id'], $resolved['source_name']];
        }
        $item = CatalogPackage::query()->where('organization_id', $organizationId)->findOrFail($snapshot['catalog_package_id']);

        return [$item->category_id, null, null, null, null, null];
    }

    private function snapshotPackageComponents(CommercialRevisionLine $line): void
    {
        $package = CatalogPackage::query()->where('organization_id', $line->organization_id)->with(['components.product.baseUom', 'components.service.salesUom', 'components.service.defaultLaborRole', 'components.componentUom'])->findOrFail($line->catalog_package_id);
        foreach ($package->components->where('active', true) as $component) {
            $item = $component->component_type === 'product' ? $component->product : $component->service;
            $serviceCost = $component->component_type === 'service' ? $this->serviceCosts->resolve($item) : null;
            $productCost = $component->component_type === 'product' && $item->default_cost_cents !== null;
            $line->components()->create([
                'organization_id' => $line->organization_id, 'component_type' => $component->component_type,
                'catalog_product_id' => $component->catalog_product_id, 'catalog_service_id' => $component->catalog_service_id,
                'source_code' => $component->component_type === 'product' ? $item->product_code : $item->service_code,
                'name' => $item->name, 'unit_code' => $component->componentUom->code, 'unit_name' => $component->componentUom->name,
                'quantity_millis' => $component->quantity_millis, 'waste_basis_points' => $component->waste_basis_points,
                'unit_sell_cents' => $component->component_type === 'product' ? $item->default_sell_price_cents : $item->default_price_cents,
                'cost_basis_cents' => $productCost ? $item->default_cost_cents : ($serviceCost['cost_cents'] ?? null),
                'cost_basis_quantity_millis' => $productCost ? $item->default_cost_quantity_millis : ($serviceCost['basis_quantity_millis'] ?? null),
                'cost_resolved' => $productCost || ($serviceCost['cost_cents'] ?? null) !== null,
                'cost_source_type' => $productCost ? 'product_default' : ($serviceCost['source_type'] ?? null),
                'cost_source_id' => $productCost ? $item->id : ($serviceCost['source_id'] ?? null),
                'cost_source_name' => $productCost ? $item->name : ($serviceCost['source_name'] ?? null),
                'customer_visible' => $component->customer_visible, 'sort_order' => $component->sort_order,
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function resolveSellPrice(CommercialRevisionLine $line, array $data): int
    {
        $mode = $data['pricing_mode'] ?? 'direct';
        if ($mode === 'catalog') {
            if ($line->catalog_unit_sell_cents === null) {
                throw ValidationException::withMessages(['pricing_mode' => 'No Catalog sell price is available.']);
            }

            return (int) $line->catalog_unit_sell_cents;
        }
        if ($mode === 'direct') {
            return (int) $data['effective_unit_sell_cents'];
        }
        if (! $line->cost_resolved || $line->resolved_cost_cents === null || $line->quantity_millis < 1) {
            throw ValidationException::withMessages(['pricing_mode' => 'Markup and margin pricing require a resolved cost.']);
        }
        $unitCost = $this->roundRatioForPrice($line->resolved_cost_cents * 1000, $line->quantity_millis);
        $basisPoints = (int) ($data['pricing_value_basis_points'] ?? 0);
        if ($mode === 'markup') {
            return $this->roundRatioForPrice($unitCost * (10000 + $basisPoints), 10000);
        }
        if ($mode === 'margin' && $basisPoints < 10000) {
            return $this->roundRatioForPrice($unitCost * 10000, 10000 - $basisPoints);
        }
        throw ValidationException::withMessages(['pricing_mode' => 'Choose a valid pricing mode and percentage.']);
    }

    private function roundRatioForPrice(int $numerator, int $denominator): int
    {
        if ($denominator < 1) {
            throw ValidationException::withMessages(['pricing_mode' => 'The pricing calculation is invalid.']);
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
