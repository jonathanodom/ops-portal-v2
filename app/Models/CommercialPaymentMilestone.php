<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'commercial_revision_id', 'name', 'amount_type', 'amount_value', 'allocated_cents', 'is_balancing', 'sort_order'])]
class CommercialPaymentMilestone extends Model
{
    protected function casts(): array
    {
        return ['is_balancing' => 'boolean'];
    }
}
