<?php

namespace App\Jobs;

use App\Models\ProjectAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteRemovedProjectAttachment implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $attachmentId) {}

    public function handle(): void
    {
        $attachment = ProjectAttachment::query()->where('state', 'removed')->find($this->attachmentId);

        if ($attachment) {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_key);
        }
    }
}
