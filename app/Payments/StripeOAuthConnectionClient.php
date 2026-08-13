<?php

namespace App\Payments;

use App\Contracts\StripeConnectionClient;
use Stripe\StripeClient;

class StripeOAuthConnectionClient implements StripeConnectionClient
{
    public function authorizationUrl(string $environment, string $clientId, string $redirectUri, string $state): string
    {
        return 'https://connect.stripe.com/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'scope' => 'read_write',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    public function exchange(string $environment, string $platformSecret, string $code): array
    {
        $response = $this->client($platformSecret)->oauth->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_secret' => $platformSecret,
        ]);

        return [
            'account_id' => (string) $response->stripe_user_id,
            'access_token' => filled($response->access_token ?? null) ? (string) $response->access_token : null,
            'refresh_token' => filled($response->refresh_token ?? null) ? (string) $response->refresh_token : null,
            'livemode' => (bool) $response->livemode,
        ];
    }

    public function profile(string $environment, string $platformSecret, string $accountId): array
    {
        $account = $this->client($platformSecret)->accounts->retrieve($accountId, []);
        $name = $account->business_profile?->name
            ?? $account->company?->name
            ?? trim(implode(' ', array_filter([$account->individual?->first_name, $account->individual?->last_name])))
            ?: null;

        return [
            'account_id' => (string) $account->id,
            'account_name' => $name,
            'payments_enabled' => (bool) $account->charges_enabled,
        ];
    }

    public function deauthorize(string $environment, string $clientId, string $platformSecret, string $accountId): void
    {
        $response = $this->client($platformSecret)->oauth->deauthorize([
            'client_id' => $clientId,
            'stripe_user_id' => $accountId,
        ]);
        if (! hash_equals($accountId, (string) $response->stripe_user_id)) {
            throw new \RuntimeException('stripe_deauthorization_account_mismatch');
        }
    }

    private function client(string $platformSecret): StripeClient
    {
        return new StripeClient($platformSecret);
    }
}
