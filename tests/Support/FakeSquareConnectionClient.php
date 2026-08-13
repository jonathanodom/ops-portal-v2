<?php

namespace Tests\Support;

use App\Contracts\SquareConnectionClient;

class FakeSquareConnectionClient implements SquareConnectionClient
{
    /** @var array<string, mixed> */
    public array $exchangeResult = ['access_token' => 'square-access-token', 'refresh_token' => 'square-refresh-token', 'expires_at' => '2026-09-12T12:00:00Z', 'merchant_id' => 'MERCHANT-1'];

    /** @var array<string, mixed> */
    public array $refreshResult = ['access_token' => 'square-access-refreshed', 'refresh_token' => 'square-refresh-refreshed', 'expires_at' => '2026-10-12T12:00:00Z', 'merchant_id' => 'MERCHANT-1'];

    /** @var array<string, mixed> */
    public array $profileResult = ['merchant_id' => 'MERCHANT-1', 'merchant_name' => 'NewDay Square Merchant', 'main_location_id' => 'LOC-2', 'locations' => [['id' => 'LOC-1', 'name' => 'Dallas'], ['id' => 'LOC-2', 'name' => 'Graham']]];

    public bool $failExchange = false;

    public bool $failRefresh = false;

    public bool $failRevoke = false;

    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function authorizationUrl(string $environment, string $applicationId, string $redirectUri, string $state, string $codeChallenge, array $scopes): string
    {
        $this->calls[] = compact('environment', 'applicationId', 'redirectUri', 'state', 'codeChallenge', 'scopes');

        return 'https://connect.squareupsandbox.com/oauth2/authorize?'.http_build_query(['state' => $state, 'scope' => implode(' ', $scopes)]);
    }

    public function exchange(string $environment, string $applicationId, string $applicationSecret, string $code, string $redirectUri, string $codeVerifier): array
    {
        $this->calls[] = compact('environment', 'applicationId', 'applicationSecret', 'code', 'redirectUri', 'codeVerifier');
        if ($this->failExchange) {
            throw new \RuntimeException('provider_exchange_secret_must_not_leak');
        }

        return $this->exchangeResult;
    }

    public function refresh(string $environment, string $applicationId, string $applicationSecret, string $refreshToken): array
    {
        $this->calls[] = compact('environment', 'applicationId', 'applicationSecret', 'refreshToken');
        if ($this->failRefresh) {
            throw new \RuntimeException('provider_refresh_secret_must_not_leak');
        }

        return $this->refreshResult;
    }

    public function profile(string $environment, string $accessToken): array
    {
        $this->calls[] = compact('environment', 'accessToken');

        return $this->profileResult;
    }

    public function revoke(string $environment, string $applicationId, string $applicationSecret, string $accessToken): void
    {
        $this->calls[] = compact('environment', 'applicationId', 'applicationSecret', 'accessToken');
        if ($this->failRevoke) {
            throw new \RuntimeException('provider_revoke_secret_must_not_leak');
        }
    }
}
