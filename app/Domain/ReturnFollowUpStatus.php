<?php

namespace App\Domain;

final class ReturnFollowUpStatus
{
    public const NEEDS_REVIEW = 'needs_review';

    public const WAITING_ON_PARTS = 'waiting_on_parts';

    public const READY_TO_SCHEDULE = 'ready_to_schedule';

    public const SCHEDULED = 'scheduled';

    public const COMPLETED = 'completed';

    public const CANCELED = 'canceled';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::NEEDS_REVIEW,
            self::WAITING_ON_PARTS,
            self::READY_TO_SCHEDULE,
            self::SCHEDULED,
            self::COMPLETED,
            self::CANCELED,
        ];
    }
}
