<?php

namespace App\Domain\Notifications;

final readonly class PushDeliveryResult
{
    public function __construct(
        public bool $successful,
        public bool $permanentlyInvalid = false,
        public ?string $failureCode = null,
    ) {}
}
