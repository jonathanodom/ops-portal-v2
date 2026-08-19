<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'project_id', 'uploaded_by_id', 'category', 'state', 'storage_disk',
    'storage_key', 'original_name', 'mime_type', 'byte_size', 'caption', 'removed_at', 'removed_by_id',
])]
class ProjectAttachment extends Model
{
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_id');
    }
}
