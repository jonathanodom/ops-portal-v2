<?php

namespace App\Jobs;

use App\Models\VisitMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteRemovedVisitMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $mediaId) {}

    public function handle(): void
    {
        $media = VisitMedia::query()->where('state', 'removed')->find($this->mediaId);

        if ($media) {
            Storage::disk($media->storage_disk)->delete($media->storage_key);
        }
    }
}
