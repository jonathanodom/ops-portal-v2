<?php

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

class RecordingStripeHttpClient implements ClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, array{0: string, 1: int, 2: array<string, string>}> */
    public array $responses = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params', 'hasFile', 'apiMode', 'maxNetworkRetries');

        return array_shift($this->responses) ?? ['{}', 200, []];
    }
}
