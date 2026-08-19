<?php

namespace App\Domain\Projects\Data;

final readonly class ContactSummary
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $name,
        public ?string $role,
        public bool $active,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
