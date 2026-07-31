<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ScheduleWindow
{
    /** @return array{start: CarbonImmutable, end: CarbonImmutable}|null */
    public function fromLocal(?string $start, ?string $end, string $timezone): ?array
    {
        if (blank($start) && blank($end)) {
            return null;
        }
        if (blank($start) || blank($end)) {
            throw ValidationException::withMessages(['scheduled_start' => 'Enter both the start and end of the visit window.']);
        }

        $localStart = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $start, $timezone);
        $localEnd = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $end, $timezone);
        if (! $localStart || $localStart->format('Y-m-d\TH:i') !== $start) {
            throw ValidationException::withMessages(['scheduled_start' => 'The start time does not exist in the service location timezone.']);
        }
        if (! $localEnd || $localEnd->format('Y-m-d\TH:i') !== $end) {
            throw ValidationException::withMessages(['scheduled_end' => 'The end time does not exist in the service location timezone.']);
        }
        if ($localEnd->lessThanOrEqualTo($localStart)) {
            throw ValidationException::withMessages(['scheduled_end' => 'The visit end must be after its start.']);
        }

        return ['start' => $localStart->utc(), 'end' => $localEnd->utc()];
    }
}
