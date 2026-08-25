<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'closeout_id', 'signer_name', 'signer_role', 'statement_version', 'statement_snapshot', 'storage_disk', 'storage_key', 'mime_type', 'size_bytes', 'sha256', 'signed_at', 'captured_by_id'])]
class CloseoutAcknowledgmentSignature extends Model
{
    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function closeout(): BelongsTo
    {
        return $this->belongsTo(Closeout::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_id');
    }
}
