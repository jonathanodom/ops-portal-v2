<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'proposal_acceptance_id', 'source_milestone_id', 'name', 'amount_type', 'amount_value', 'allocated_cents', 'is_balancing', 'sort_order'])]
class AcceptedPaymentMilestone extends Model
{
    protected function casts(): array
    {
        return ['is_balancing' => 'boolean'];
    }
}
