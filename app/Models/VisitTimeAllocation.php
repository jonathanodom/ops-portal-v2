<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'visit_time_allocation_set_id', 'service_ticket_work_item_id', 'allocated_seconds', 'position'])]
class VisitTimeAllocation extends Model
{
    public function allocationSet(): BelongsTo
    {
        return $this->belongsTo(VisitTimeAllocationSet::class, 'visit_time_allocation_set_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(ServiceTicketWorkItem::class, 'service_ticket_work_item_id');
    }
}
