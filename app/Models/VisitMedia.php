<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'visit_id', 'closeout_id', 'uploader_id', 'storage_disk', 'storage_key', 'mime_type', 'byte_size', 'category', 'caption', 'state', 'removed_at', 'removed_by_id'])]
class VisitMedia extends Model
{
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
