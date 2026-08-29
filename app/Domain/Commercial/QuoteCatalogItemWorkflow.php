<?php

namespace App\Domain\Commercial;

use App\Models\CatalogPackage;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CommercialRevision;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class QuoteCatalogItemWorkflow
{
    public function __construct(private readonly QuoteWorkflow $quotes, private readonly AuditRecorder $audit) {}

    /** @param array<string,mixed> $catalogData @param array<string,mixed> $lineData */
    public function createAndAdd(Organization $organization, CommercialRevision $revision, User $actor, string $type, array $catalogData, array $lineData): Model
    {
        return DB::transaction(function () use ($organization, $revision, $actor, $type, $catalogData, $lineData): Model {
            $class = match ($type) {
                'product' => CatalogProduct::class,
                'service' => CatalogService::class,
                'package' => CatalogPackage::class,
            };
            $item = $class::query()->create($catalogData + [
                'organization_id' => $organization->id,
                'active' => true,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->quotes->addCatalogLine($revision, $actor, $lineData + [
                'catalog_item_type' => $type,
                'catalog_item_id' => $item->id,
            ]);
            $this->audit->record($organization, $actor, "catalog.{$type}_created_from_quote", $item, [
                "{$type}_id" => $item->id,
                'quote_revision_id' => $revision->id,
                'changed_fields' => array_keys($catalogData),
            ]);

            return $item;
        });
    }
}
