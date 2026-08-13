<?php

namespace App\Contracts;

interface StripeConnectionClient
{
    public function authorizationUrl(string $environment, string $clientId, string $redirectUri, string $state): string;

    /** @return array{account_id: string, access_token: ?string, refresh_token: ?string, livemode: bool} */
    public function exchange(string $environment, string $platformSecret, string $code): array;

    /** @return array{account_id: string, account_name: ?string, payments_enabled: bool} */
    public function profile(string $environment, string $platformSecret, string $accountId): array;

    public function deauthorize(string $environment, string $clientId, string $platformSecret, string $accountId): void;
}
