<?php

namespace App\Support\Api;

use RuntimeException;

/**
 * A concurrent request with the same Idempotency-Key is still inside its
 * transaction. Extremely narrow window; the caller should retry shortly.
 */
class IdempotencyKeyInFlightException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A request with this Idempotency-Key is already being processed. Retry shortly.');
    }
}
