<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'visit_id', 'closeout_id', 'user_id', 'active_user_id', 'category', 'started_at', 'ended_at', 'source', 'note', 'correction_reason'])]
class VisitTimeEntry extends Model
{
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class)->withTrashed();
    }
}
