<?php

namespace App\Payments;

use App\Contracts\SquareConnectionClient;
use Square\Environments;
use Square\OAuth\Requests\ObtainTokenRequest;
use Square\OAuth\Requests\RevokeTokenRequest;
use Square\SquareClient;

class SquareOAuthConnectionClient implements SquareConnectionClient
{
    public function authorizationUrl(string $environment, string $applicationId, string $redirectUri, string $state, string $codeChallenge, array $scopes): string
    {
        $base = $environment === 'production' ? Environments::Production->value : Environments::Sandbox->value;

        return $base.'/oauth2/authorize?'.http_build_query([
            'client_id' => $applicationId,
            'scope' => implode(' ', $scopes),
            'session' => 'false',
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $environment, string $applicationId, string $applicationSecret, string $code, string $redirectUri, string $codeVerifier): array
    {
        $response = $this->oauthClient($environment)->oAuth->obtainToken(new ObtainTokenRequest([
            'clientId' => $applicationId,
            'clientSecret' => $applicationSecret,
            'code' => $code,
            'redirectUri' => $redirectUri,
            'grantType' => 'authorization_code',
            'codeVerifier' => $codeVerifier,
        ]));

        return $this->tokens($response);
    }

    public function refresh(string $environment, string $applicationId, string $applicationSecret, string $refreshToken): array
    {
        $response = $this->oauthClient($environment)->oAuth->obtainToken(new ObtainTokenRequest([
            'clientId' => $applicationId,
            'clientSecret' => $applicationSecret,
            'grantType' => 'refresh_token',
            'refreshToken' => $refreshToken,
        ]));

        return $this->tokens($response);
    }

    public function profile(string $environment, string $accessToken): array
    {
        $client = new SquareClient($accessToken, options: ['baseUrl' => $this->baseUrl($environment)]);
        $merchant = collect(iterator_to_array($client->merchants->list()))->first();
        if (! $merchant?->getId()) {
            throw new \RuntimeException('square_merchant_not_found');
        }
        $locations = collect($client->locations->list()->getLocations() ?? [])
            ->filter(fn ($location): bool => $location->getStatus() === 'ACTIVE' && filled($location->getId()))
            ->map(fn ($location): array => ['id' => (string) $location->getId(), 'name' => (string) ($location->getName() ?: $location->getId())])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [
            'merchant_id' => (string) $merchant->getId(),
            'merchant_name' => $merchant->getBusinessName(),
            'main_location_id' => $merchant->getMainLocationId(),
            'locations' => $locations,
        ];
    }

    public function revoke(string $environment, string $applicationId, string $applicationSecret, string $accessToken): void
    {
        $client = new SquareClient($applicationSecret, options: [
            'baseUrl' => $this->baseUrl($environment),
            'headers' => ['Authorization' => 'Client '.$applicationSecret],
        ]);
        $client->oAuth->revokeToken(new RevokeTokenRequest(['clientId' => $applicationId, 'accessToken' => $accessToken]));
    }

    private function oauthClient(string $environment): SquareClient
    {
        return new SquareClient('oauth-connection', options: ['baseUrl' => $this->baseUrl($environment)]);
    }

    private function baseUrl(string $environment): string
    {
        return $environment === 'production' ? Environments::Production->value : Environments::Sandbox->value;
    }

    /** @return array{access_token: string, refresh_token: string, expires_at: ?string, merchant_id: string} */
    private function tokens(object $response): array
    {
        if (! $response->getAccessToken() || ! $response->getRefreshToken() || ! $response->getMerchantId()) {
            throw new \RuntimeException('square_oauth_response_incomplete');
        }

        return [
            'access_token' => (string) $response->getAccessToken(),
            'refresh_token' => (string) $response->getRefreshToken(),
            'expires_at' => $response->getExpiresAt(),
            'merchant_id' => (string) $response->getMerchantId(),
        ];
    }
}
