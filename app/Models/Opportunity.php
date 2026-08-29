<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'opportunity_number', 'customer_id', 'service_location_id', 'primary_contact_id', 'owner_user_id', 'stage_id', 'title', 'priority', 'estimated_value_cents', 'estimated_close_on', 'probability_override_bps', 'lead_source', 'referral_source', 'classification', 'next_action', 'lost_reason', 'lost_note', 'lost_at', 'won_at', 'created_by_id', 'updated_by_id'])]
class Opportunity extends Model
{
    protected function casts(): array
    {
        return ['estimated_close_on' => 'date', 'lost_at' => 'datetime', 'won_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceLocation::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OpportunityStage::class, 'stage_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OpportunityTask::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OpportunityActivity::class)->latest('occurred_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OpportunityAttachment::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(CommercialDocument::class)->where('document_type', 'quote')->latest();
    }

    public function storedAttachments(): HasMany
    {
        return $this->attachments()->where('state', 'stored')->latest();
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function probabilityBps(): int
    {
        return $this->probability_override_bps ?? $this->stage->default_probability_bps;
    }
}
