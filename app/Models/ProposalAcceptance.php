<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'proposal_publication_id', 'commercial_revision_id', 'proposal_recipient_id', 'proposal_share_link_id', 'publication_hash', 'revision_content_hash', 'accepted_snapshot', 'accepted_snapshot_hash', 'subtotal_cents', 'discount_cents', 'tax_cents', 'total_cents', 'change_order_delta_cents', 'resulting_project_total_cents', 'signer_name', 'signer_email', 'signer_title', 'consent_statement', 'consent_version', 'signature_disk', 'signature_key', 'signature_mime_type', 'signature_byte_size', 'signature_width', 'signature_height', 'signature_sha256', 'signed_at', 'encrypted_ip', 'ip_hash', 'user_agent', 'idempotency_token'])]
class ProposalAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'accepted_snapshot' => 'array', 'signer_name' => 'encrypted', 'signer_email' => 'encrypted',
            'signer_title' => 'encrypted', 'encrypted_ip' => 'encrypted', 'signed_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ProposalPublication::class, 'proposal_publication_id');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(ProposalAcceptanceLineSelection::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(AcceptedPaymentMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function projectScope(): HasOne
    {
        return $this->hasOne(ProjectCommercialScope::class);
    }
}
