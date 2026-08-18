<?php

namespace App\Jobs;

use App\Models\ServiceTicketFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteRemovedServiceTicketFile implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $fileId) {}

    public function handle(): void
    {
        $file = ServiceTicketFile::query()->where('state', 'removed')->find($this->fileId);

        if ($file) {
            Storage::disk($file->storage_disk)->delete($file->storage_key);
        }
    }
}
