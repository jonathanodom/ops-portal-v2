<?php

namespace App\Domain\Projects\Data;

use Carbon\CarbonImmutable;

final readonly class TicketSummary
{
    public function __construct(
        public int $id,
        public int $customerId,
        public int $serviceLocationId,
        public string $ticketNumber,
        public string $title,
        public ?string $purpose,
        public string $priority,
        public string $status,
        public string $locationName,
        public CarbonImmutable $updatedAt,
        public int $visitCount = 0,
    ) {}
}
