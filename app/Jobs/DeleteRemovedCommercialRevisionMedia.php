<?php

namespace App\Jobs;

use App\Models\CommercialRevisionMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteRemovedCommercialRevisionMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $mediaId) {}

    public function handle(): void
    {
        $media = CommercialRevisionMedia::query()->find($this->mediaId);
        if ($media?->state === 'removed' && $media->storage_disk && $media->storage_key) {
            Storage::disk($media->storage_disk)->delete($media->storage_key);
        }
    }
}
