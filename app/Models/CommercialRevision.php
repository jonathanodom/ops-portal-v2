<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'commercial_document_id', 'version', 'source_revision_id', 'status', 'currency', 'commercial_terms_set_id', 'terms_name_snapshot', 'terms_version_snapshot', 'terms_body_snapshot', 'terms_overridden', 'discount_type', 'discount_value', 'tax_rate_basis_points', 'tax_rate_overridden', 'tax_override_reason', 'customer_tax_exempt', 'tax_exemption_reference', 'subtotal_cents', 'line_discount_total_cents', 'quote_discount_total_cents', 'tax_total_cents', 'total_cents', 'change_order_delta_cents', 'resulting_project_total_cents', 'resolved_cost_cents', 'cost_complete', 'gross_profit_cents', 'gross_margin_basis_points', 'markup_basis_points', 'content_version', 'content_hash', 'locked_at', 'created_by_id', 'updated_by_id'])]
class CommercialRevision extends Model
{
    public const STATUSES = ['draft', 'pending_approval', 'approved', 'published'];

    protected function casts(): array
    {
        return ['terms_overridden' => 'boolean', 'tax_rate_overridden' => 'boolean', 'customer_tax_exempt' => 'boolean', 'cost_complete' => 'boolean', 'locked_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CommercialDocument::class, 'commercial_document_id');
    }

    public function sourceRevision(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_revision_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommercialRevisionLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CommercialRevisionLocation::class)->orderBy('sort_order')->orderBy('id');
    }

    public function systems(): HasMany
    {
        return $this->hasMany(CommercialRevisionSystem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(CommercialRevisionPhase::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CommercialRevisionSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function paymentMilestones(): HasMany
    {
        return $this->hasMany(CommercialPaymentMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function termsSet(): BelongsTo
    {
        return $this->belongsTo(CommercialTermsSet::class, 'commercial_terms_set_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(CommercialRevisionMedia::class)->orderBy('id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CommercialRevisionApproval::class)->latest();
    }

    public function publication(): HasOne
    {
        return $this->hasOne(ProposalPublication::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function displayNumber(): string
    {
        return $this->document->document_number.'-V'.$this->version;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft' && $this->locked_at === null;
    }
}
