<?php

namespace App\Jobs;

use App\Models\OpportunityAttachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteRemovedOpportunityAttachment implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $attachmentId) {}

    public function handle(): void
    {
        $attachment = OpportunityAttachment::query()->find($this->attachmentId);
        if ($attachment?->state === 'removed') {
            Storage::disk($attachment->storage_disk)->delete($attachment->storage_key);
        }
    }
}
