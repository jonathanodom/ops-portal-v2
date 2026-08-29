<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'commercial_revision_id', 'media_type', 'storage_disk', 'storage_key', 'original_name', 'mime_type', 'byte_size', 'sha256', 'embed_url', 'caption', 'state', 'uploaded_by_id', 'removed_at', 'removed_by_id'])]
class CommercialRevisionMedia extends Model
{
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }
}
