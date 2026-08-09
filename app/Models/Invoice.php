<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'customer_id', 'service_location_id', 'service_ticket_id', 'billing_handoff_id', 'reissue_of_invoice_id', 'generation', 'invoice_number', 'status', 'currency', 'payment_terms', 'due_on', 'billing_name', 'billing_legal_name', 'billing_contact_name', 'billing_email', 'billing_phone', 'billing_address_line_1', 'billing_address_line_2', 'billing_city', 'billing_state', 'billing_postal_code', 'seller_name', 'seller_legal_name', 'seller_email', 'seller_phone', 'seller_address_line_1', 'seller_address_line_2', 'seller_city', 'seller_state', 'seller_postal_code', 'discount_type', 'discount_value', 'tax_rate_basis_points', 'tax_override_reason', 'discount_reason', 'customer_note', 'internal_note', 'subtotal_cents', 'discount_total_cents', 'tax_total_cents', 'total_cents', 'creation_token', 'issue_token', 'issued_at', 'issued_by_id', 'voided_at', 'voided_by_id', 'void_reason', 'void_token', 'pdf_status', 'pdf_disk', 'pdf_key', 'pdf_sha256', 'pdf_failure_code', 'created_by_id', 'updated_by_id'])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return ['due_on' => 'date', 'issued_at' => 'datetime', 'voided_at' => 'datetime'];
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

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function billingHandoff(): BelongsTo
    {
        return $this->belongsTo(BillingHandoff::class);
    }

    public function reissueOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissue_of_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function closeoutLinks(): HasMany
    {
        return $this->hasMany(InvoiceCloseout::class);
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(InvoiceAcknowledgment::class)->latest('acknowledged_at');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ready_for_review'], true);
    }
}
