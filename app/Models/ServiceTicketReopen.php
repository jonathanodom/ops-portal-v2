<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'service_ticket_id', 'from_status', 'reason', 'prior_status_reason',
    'prior_status_changed_at', 'prior_status_changed_by_id', 'reopened_by_id', 'reopened_at',
])]
class ServiceTicketReopen extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'prior_status_changed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceTicket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function priorStatusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prior_status_changed_by_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_id');
    }
}
