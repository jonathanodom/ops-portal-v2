<?php

namespace App\Support\Api;

use RuntimeException;

class IdempotencyKeyReusedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used for a different request.');
    }
}
