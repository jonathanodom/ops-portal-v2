<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'status', 'engagement_status', 'next_follow_up_at',
    'engagement_changed_by_id', 'engagement_changed_at', 'source',
    'first_name', 'last_name', 'phone', 'email', 'customer_type', 'zip', 'company',
    'service_interest', 'selected_plan', 'preferred_contact', 'timeline', 'details',
    'originating_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer',
    'contact_consent_at', 'contact_consent_ip', 'contact_consent_version',
    'sms_consent_at', 'sms_consent_ip', 'sms_consent_version',
    'ip_address', 'user_agent', 'payload', 'payload_sha256', 'received_at', 'error_message',
    'opportunity_id', 'converted_at', 'converted_by_id',
])]
class CommercialLeadIntake extends Model
{
    public const STATUSES = ['received', 'converted', 'archived', 'spam', 'failed'];

    public const SOURCES = ['website', 'manual'];

    public const ENGAGEMENT_STATUSES = [
        'new',
        'attempted_contact',
        'left_voicemail',
        'contacted',
        'waiting_on_customer',
        'follow_up_needed',
        'qualified',
        'not_qualified',
        'closed_no_response',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'contact_consent_at' => 'datetime',
            'sms_consent_at' => 'datetime',
            'received_at' => 'datetime',
            'converted_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'engagement_changed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by_id');
    }

    public function engagementChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'engagement_changed_by_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CommercialLeadActivity::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function engagementStatus(): string
    {
        return $this->status === 'converted' ? 'converted' : ($this->engagement_status ?? 'new');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
