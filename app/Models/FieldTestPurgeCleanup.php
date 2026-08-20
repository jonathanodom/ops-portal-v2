<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['public_id', 'organization_id', 'actor_id', 'storage_manifest', 'record_counts', 'status', 'failure_count', 'completed_at'])]
class FieldTestPurgeCleanup extends Model
{
    protected $hidden = ['storage_manifest'];

    protected function casts(): array
    {
        return [
            'storage_manifest' => 'encrypted:array',
            'record_counts' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
