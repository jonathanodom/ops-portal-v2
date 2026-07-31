<?php

namespace App\Support;

use App\Models\DocumentSequence;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class ServiceTicketNumber
{
    public function next(Organization $organization): string
    {
        return DB::transaction(function () use ($organization): string {
            $year = now($organization->timezone)->year;

            DocumentSequence::query()->insertOrIgnore([
                'organization_id' => $organization->id,
                'document_type' => 'service_ticket',
                'year' => $year,
                'current_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::query()
                ->where('organization_id', $organization->id)
                ->where('document_type', 'service_ticket')
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->increment('current_value');
            $sequence->refresh();

            return sprintf('NDT-ST-%d-%04d', $year, $sequence->current_value);
        });
    }
}
