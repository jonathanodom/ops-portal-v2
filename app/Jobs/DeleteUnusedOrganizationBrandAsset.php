<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBrandAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeleteUnusedOrganizationBrandAsset implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $assetId) {}

    public function handle(): void
    {
        $asset = OrganizationBrandAsset::query()->find($this->assetId);
        if (! $asset) {
            return;
        }
        if (Organization::query()->where('full_logo_asset_id', $asset->id)->orWhere('mark_logo_asset_id', $asset->id)->exists()
            || Invoice::query()->where('seller_logo_asset_id', $asset->id)->exists()) {
            return;
        }
        Storage::disk($asset->storage_disk)->delete($asset->storage_key);
        $asset->delete();
    }
}
