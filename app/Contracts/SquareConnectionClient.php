<?php

namespace App\Contracts;

interface SquareConnectionClient
{
    /** @param array<int, string> $scopes */
    public function authorizationUrl(string $environment, string $applicationId, string $redirectUri, string $state, string $codeChallenge, array $scopes): string;

    /** @return array{access_token: string, refresh_token: string, expires_at: ?string, merchant_id: string} */
    public function exchange(string $environment, string $applicationId, string $applicationSecret, string $code, string $redirectUri, string $codeVerifier): array;

    /** @return array{access_token: string, refresh_token: string, expires_at: ?string, merchant_id: string} */
    public function refresh(string $environment, string $applicationId, string $applicationSecret, string $refreshToken): array;

    /** @return array{merchant_id: string, merchant_name: ?string, main_location_id: ?string, locations: array<int, array{id: string, name: string}>} */
    public function profile(string $environment, string $accessToken): array;

    public function revoke(string $environment, string $applicationId, string $applicationSecret, string $accessToken): void;
}
