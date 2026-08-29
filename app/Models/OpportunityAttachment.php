<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'opportunity_id', 'uploaded_by_id', 'state', 'storage_disk', 'storage_key', 'original_name', 'mime_type', 'byte_size', 'caption', 'removed_at', 'removed_by_id'])]
class OpportunityAttachment extends Model
{
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
