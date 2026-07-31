<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'visit_id', 'closeout_id', 'proposed_by_id', 'description', 'quantity', 'unit', 'serial_mac', 'billing_treatment', 'technician_note', 'removed_at'])]
class VisitPartProposal extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'removed_at' => 'datetime'];
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }
}
