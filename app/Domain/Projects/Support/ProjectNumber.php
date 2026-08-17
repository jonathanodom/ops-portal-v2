<?php

namespace App\Domain\Projects\Support;

use App\Models\DocumentSequence;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

final class ProjectNumber
{
    public function next(Organization $organization): string
    {
        return DB::transaction(function () use ($organization): string {
            $year = now($organization->timezone)->year;
            DocumentSequence::query()->insertOrIgnore([
                'organization_id' => $organization->id,
                'document_type' => 'project',
                'year' => $year,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DocumentSequence::query()->where('organization_id', $organization->id)
                ->where('document_type', 'project')->where('year', $year)->lockForUpdate()->firstOrFail();
            $sequence->increment('current_value');
            $sequence->refresh();

            return sprintf('NDT-PRJ-%d-%04d', $year, $sequence->current_value);
        });
    }
}
