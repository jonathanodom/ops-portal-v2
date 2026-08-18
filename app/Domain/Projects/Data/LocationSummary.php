<?php

namespace App\Domain\Projects\Data;

final readonly class LocationSummary
{
    public function __construct(public int $id, public int $customerId, public string $name, public string $address, public bool $active) {}
}
