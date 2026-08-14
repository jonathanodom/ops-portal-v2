<?php

namespace App\Domain;

use App\Models\Closeout;

class ApprovedVisitLaborMinutes
{
    public function calculate(Closeout $closeout): int
    {
        $closeout->loadMissing(['visit.timeEntries', 'reviews.adjustments']);
        $review = $closeout->reviews
            ->where('decision', 'approved')
            ->sortByDesc('id')
            ->first();
        if (! $review) {
            return 0;
        }
        $adjustments = $review->adjustments->where('type', 'time')->keyBy('visit_time_entry_id');

        return (int) $closeout->visit->timeEntries
            ->whereIn('category', ['on_site', 'other'])
            ->sum(function ($entry) use ($adjustments): int {
                $adjustment = $adjustments->get($entry->id);
                if ($adjustment?->excluded || ! $entry->ended_at) {
                    return 0;
                }

                return $adjustment?->approved_minutes
                    ?? (int) ceil($entry->started_at->diffInSeconds($entry->ended_at) / 60);
            });
    }
}
