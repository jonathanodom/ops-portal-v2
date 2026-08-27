<?php

namespace App\Domain\Commercial;

use App\Models\DocumentSequence;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

final class QuoteNumber
{
    public function next(Organization $organization): string
    {
        return DB::transaction(function () use ($organization): string {
            $year = now($organization->timezone)->year;
            DocumentSequence::query()->insertOrIgnore(['organization_id' => $organization->id, 'document_type' => 'quote', 'year' => $year, 'current_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = DocumentSequence::query()->where('organization_id', $organization->id)->where('document_type', 'quote')->where('year', $year)->lockForUpdate()->firstOrFail();
            $sequence->increment('current_value');

            return sprintf('Q-%d-%04d', $year, $sequence->fresh()->current_value);
        });
    }
}
