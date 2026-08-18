<?php

namespace App\Domain\Projects\Data;

final readonly class CustomerSummary
{
    public function __construct(public int $id, public string $displayName, public ?string $legalName, public string $status) {}
}
