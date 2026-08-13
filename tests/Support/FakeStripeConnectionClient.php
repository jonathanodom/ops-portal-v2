<?php

namespace Tests\Support;

use App\Contracts\StripeConnectionClient;

class FakeStripeConnectionClient implements StripeConnectionClient
{
    /** @var array{account_id: string, access_token: ?string, refresh_token: ?string, livemode: bool} */
    public array $exchangeResult = ['account_id' => 'acct_newday', 'access_token' => 'stripe-oauth-access', 'refresh_token' => 'stripe-oauth-refresh', 'livemode' => false];

    /** @var array{account_id: string, account_name: ?string, payments_enabled: bool} */
    public array $profileResult = ['account_id' => 'acct_newday', 'account_name' => 'NewDay Tech LLC', 'payments_enabled' => true];

    public bool $failExchange = false;

    public bool $failProfile = false;

    public bool $failDeauthorize = false;

    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function authorizationUrl(string $environment, string $clientId, string $redirectUri, string $state): string
    {
        $this->calls[] = compact('environment', 'clientId', 'redirectUri', 'state');

        return 'https://connect.stripe.com/oauth/authorize?'.http_build_query(['client_id' => $clientId, 'state' => $state, 'scope' => 'read_write']);
    }

    public function exchange(string $environment, string $platformSecret, string $code): array
    {
        $this->calls[] = compact('environment', 'platformSecret', 'code');
        if ($this->failExchange) {
            throw new \RuntimeException('stripe_exchange_secret_must_not_leak');
        }

        return $this->exchangeResult;
    }

    public function profile(string $environment, string $platformSecret, string $accountId): array
    {
        $this->calls[] = compact('environment', 'platformSecret', 'accountId');
        if ($this->failProfile) {
            throw new \RuntimeException('stripe_profile_secret_must_not_leak');
        }

        return $this->profileResult;
    }

    public function deauthorize(string $environment, string $clientId, string $platformSecret, string $accountId): void
    {
        $this->calls[] = compact('environment', 'clientId', 'platformSecret', 'accountId');
        if ($this->failDeauthorize) {
            throw new \RuntimeException('stripe_deauthorization_secret_must_not_leak');
        }
    }
}
