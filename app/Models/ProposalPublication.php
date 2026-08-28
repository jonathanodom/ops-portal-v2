<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'commercial_revision_id', 'proposal_template_id', 'status', 'revision_content_hash', 'publication_hash', 'snapshot', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'acceptance_enabled', 'show_line_details', 'show_location_totals', 'labor_grouping', 'show_manufacturer_model', 'show_product_images', 'show_package_components', 'brand_asset_id', 'expires_at', 'pdf_status', 'pdf_disk', 'pdf_key', 'pdf_sha256', 'pdf_failure_code', 'published_by_id', 'published_at', 'withdrawn_by_id', 'withdrawn_at'])]
class ProposalPublication extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'acceptance_enabled' => 'boolean', 'show_line_details' => 'boolean', 'show_location_totals' => 'boolean', 'show_manufacturer_model' => 'boolean', 'show_product_images' => 'boolean', 'show_package_components' => 'boolean', 'expires_at' => 'datetime', 'published_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CommercialRevision::class, 'commercial_revision_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalTemplate::class, 'proposal_template_id');
    }

    public function brandAsset(): BelongsTo
    {
        return $this->belongsTo(OrganizationBrandAsset::class, 'brand_asset_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ProposalRecipient::class);
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ProposalShareLink::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ProposalDeliveryAttempt::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
