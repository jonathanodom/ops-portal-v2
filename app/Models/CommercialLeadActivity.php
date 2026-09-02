<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'commercial_lead_intake_id', 'actor_id', 'event_type',
    'from_status', 'to_status', 'next_follow_up_at', 'note', 'occurred_at',
])]
class CommercialLeadActivity extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'next_follow_up_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CommercialLeadIntake::class, 'commercial_lead_intake_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
