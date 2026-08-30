<?php

namespace Tests\Unit;

use App\Support\Api\IdempotencyFingerprint;
use PHPUnit\Framework\TestCase;

class IdempotencyFingerprintTest extends TestCase
{
    public function test_associative_payload_order_does_not_change_the_fingerprint(): void
    {
        $first = IdempotencyFingerprint::make('post', 'api/v1/tickets', [
            'title' => 'Outage',
            'nested' => ['priority' => 'high', 'customer_id' => 12],
        ]);
        $second = IdempotencyFingerprint::make('POST', 'api/v1/tickets', [
            'nested' => ['customer_id' => 12, 'priority' => 'high'],
            'title' => 'Outage',
        ]);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, IdempotencyFingerprint::make('POST', 'api/v1/tickets', ['title' => 'Changed']));
    }
}
