<?php

namespace App\Domain;

use App\Contracts\StripeConnectionClient;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\PaymentProviderConfiguration;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StripeConnectionWorkflow
{
    public function __construct(
        private readonly StripeConnectionClient $stripe,
        private readonly PaymentAuthorizationStateWorkflow $authorizationStates,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{url: string, authorization_state: PaymentProviderAuthorizationState} */
    public function start(Organization $organization, User $actor, string $environment, string $redirectUri): array
    {
        $this->authorize($organization, $actor);
        $configuration = PaymentProviderConfiguration::query()->forOrganization($organization->id)->where('provider', 'stripe')->first();
        if ($configuration?->enabled || ($configuration && $this->hasOpenAttempts($configuration))) {
            throw ValidationException::withMessages(['connection' => 'Disable Stripe and resolve active checkout attempts before reconnecting it.']);
        }
        $application = $this->application($environment);
        $state = $this->authorizationStates->create($organization, $actor, 'stripe', $environment, '/office/settings/billing');
        $this->audit->record($organization, $actor, 'payments.stripe_connection_started', $state['authorization_state'], ['provider' => 'stripe', 'environment' => $environment]);

        return [
            'url' => $this->stripe->authorizationUrl($environment, $application['client_id'], $redirectUri, $state['state']),
            'authorization_state' => $state['authorization_state'],
        ];
    }

    public function callback(Organization $organization, User $actor, string $state, string $code): PaymentProviderConfiguration
    {
        $this->authorize($organization, $actor);
        $authorizationState = $this->authorizationStates->consume($organization, $actor, 'stripe', $state);
        $application = $this->application($authorizationState->environment);

        try {
            $tokens = $this->stripe->exchange($authorizationState->environment, $application['platform_secret'], $code);
            $expectedLiveMode = $authorizationState->environment === 'live';
            if ($tokens['livemode'] !== $expectedLiveMode || ! str_starts_with($tokens['account_id'], 'acct_')) {
                throw new \RuntimeException('stripe_connection_context_mismatch');
            }
            $profile = $this->stripe->profile($authorizationState->environment, $application['platform_secret'], $tokens['account_id']);
            if (! hash_equals($tokens['account_id'], $profile['account_id'])) {
                throw new \RuntimeException('stripe_account_mismatch');
            }
        } catch (Throwable) {
            $this->audit->record($organization, $actor, 'payments.stripe_connection_rejected', $authorizationState, ['provider' => 'stripe', 'environment' => $authorizationState->environment, 'failure_code' => 'provider_exchange_failed']);
            throw ValidationException::withMessages(['connection' => 'Stripe could not complete the existing-account connection. Verify that Connect OAuth is enabled and the callback URL is registered, then try again.']);
        }

        return DB::transaction(function () use ($organization, $actor, $authorizationState, $tokens, $profile): PaymentProviderConfiguration {
            $configuration = PaymentProviderConfiguration::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'provider' => 'stripe'],
                ['public_id' => (string) Str::uuid(), 'environment' => $authorizationState->environment],
            );
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            if ($configuration->enabled || $this->hasOpenAttempts($configuration)) {
                throw ValidationException::withMessages(['connection' => 'Disable Stripe and resolve active checkout attempts before reconnecting it.']);
            }
            $configuration->forceFill([
                'environment' => $authorizationState->environment,
                'connection_method' => 'oauth',
                'api_secret' => null,
                'webhook_secret' => null,
                'credential_fingerprint' => $this->fingerprint($tokens['account_id']),
                'oauth_access_token' => $tokens['access_token'],
                'oauth_refresh_token' => $tokens['refresh_token'],
                'oauth_expires_at' => null,
                'external_account_id' => $tokens['account_id'],
                'external_account_name' => $profile['account_name'],
                'payments_enabled' => $profile['payments_enabled'],
                'connection_status' => 'connected',
                'connected_at' => now(),
                'connected_by_id' => $actor->id,
                'last_refreshed_at' => now(),
                'disconnected_at' => null,
                'last_test_code' => $profile['payments_enabled'] ? 'connected' : 'payments_not_enabled',
                'last_tested_at' => now(),
                'last_tested_by_id' => $actor->id,
                'enabled' => false,
                'updated_by_id' => $actor->id,
            ])->save();
            $this->audit->record($organization, $actor, 'payments.stripe_connected', $configuration, [
                'provider' => 'stripe', 'environment' => $configuration->environment,
                'external_account_id' => $configuration->external_account_id,
                'payments_enabled' => $configuration->payments_enabled,
                'changed_fields' => ['connection_method', 'connection_status', 'external_account_id', 'payments_enabled'],
            ]);

            return $configuration->fresh();
        });
    }

    public function denied(Organization $organization, User $actor, string $state): void
    {
        $this->authorize($organization, $actor);
        $authorizationState = $this->authorizationStates->consume($organization, $actor, 'stripe', $state);
        $this->audit->record($organization, $actor, 'payments.stripe_connection_rejected', $authorizationState, [
            'provider' => 'stripe', 'environment' => $authorizationState->environment, 'failure_code' => 'authorization_denied',
        ]);
    }

    public function refresh(PaymentProviderConfiguration $configuration, User $actor): PaymentProviderConfiguration
    {
        $this->authorize($configuration->organization, $actor);
        if ($configuration->provider !== 'stripe' || $configuration->connection_method !== 'oauth' || blank($configuration->external_account_id)) {
            throw ValidationException::withMessages(['connection' => 'Stripe is not connected through hosted account authorization.']);
        }
        $application = $this->application($configuration->environment);
        try {
            $profile = $this->stripe->profile($configuration->environment, $application['platform_secret'], (string) $configuration->external_account_id);
        } catch (Throwable) {
            DB::transaction(function () use ($configuration, $actor): void {
                $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
                $configuration->update(['connection_status' => 'refresh_failed', 'enabled' => false, 'payments_enabled' => false, 'last_test_code' => 'stripe_refresh_failed', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id]);
                $this->clearDefault($configuration, $actor);
                $this->audit->record($configuration->organization, $actor, 'payments.stripe_refresh_failed', $configuration, ['provider' => 'stripe', 'failure_code' => 'provider_refresh_failed', 'changed_fields' => ['connection_status', 'enabled', 'payments_enabled']]);
            });
            throw ValidationException::withMessages(['connection' => 'Stripe could not verify the connected account. Reconnect the intended account.']);
        }
        if (! hash_equals((string) $configuration->external_account_id, $profile['account_id'])) {
            throw ValidationException::withMessages(['connection' => 'Stripe returned a different account. Reconnect the intended account.']);
        }

        return DB::transaction(function () use ($configuration, $actor, $profile): PaymentProviderConfiguration {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $configuration->forceFill([
                'external_account_name' => $profile['account_name'], 'payments_enabled' => $profile['payments_enabled'],
                'connection_status' => 'connected', 'last_refreshed_at' => now(),
                'last_test_code' => $profile['payments_enabled'] ? 'connected' : 'payments_not_enabled',
                'last_tested_at' => now(), 'last_tested_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ])->save();
            if (! $profile['payments_enabled'] && $configuration->enabled) {
                $configuration->update(['enabled' => false]);
                $this->clearDefault($configuration, $actor);
            }
            $this->audit->record($configuration->organization, $actor, 'payments.stripe_refreshed', $configuration, ['provider' => 'stripe', 'external_account_id' => $configuration->external_account_id, 'payments_enabled' => $configuration->payments_enabled, 'changed_fields' => ['connection_status', 'payments_enabled']]);

            return $configuration->fresh();
        });
    }

    public function disconnect(PaymentProviderConfiguration $configuration, User $actor, string $confirmation): void
    {
        $this->authorize($configuration->organization, $actor);
        if ($configuration->provider !== 'stripe' || $configuration->connection_method !== 'oauth' || strtoupper(trim($confirmation)) !== 'DISCONNECT STRIPE') {
            throw ValidationException::withMessages(['confirmation' => 'Enter DISCONNECT STRIPE to disconnect the account.']);
        }
        if ($configuration->enabled || $this->hasOpenAttempts($configuration)) {
            throw ValidationException::withMessages(['connection' => 'Disable Stripe and resolve active checkout attempts before disconnecting it.']);
        }
        $application = $this->application($configuration->environment);
        try {
            $this->stripe->deauthorize($configuration->environment, $application['client_id'], $application['platform_secret'], (string) $configuration->external_account_id);
        } catch (Throwable) {
            throw ValidationException::withMessages(['connection' => 'Stripe could not confirm deauthorization. The local connection was retained for a safe retry.']);
        }

        DB::transaction(function () use ($configuration, $actor): void {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $this->clearDefault($configuration, $actor);
            $configuration->forceFill([
                'oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null,
                'external_account_id' => null, 'external_account_name' => null, 'payments_enabled' => null,
                'connection_status' => 'disconnected', 'connected_at' => null, 'connected_by_id' => null,
                'last_refreshed_at' => null, 'disconnected_at' => now(), 'last_test_code' => null,
                'last_tested_at' => null, 'last_tested_by_id' => null, 'enabled' => false, 'updated_by_id' => $actor->id,
            ])->save();
            $this->audit->record($configuration->organization, $actor, 'payments.stripe_disconnected', $configuration, ['provider' => 'stripe', 'environment' => $configuration->environment, 'changed_fields' => ['connection_status', 'external_account_id', 'payments_enabled']]);
        });
    }

    /** @return array{client_id: string, platform_secret: string} */
    private function application(string $environment): array
    {
        if (! in_array($environment, ['test', 'live'], true)) {
            throw ValidationException::withMessages(['environment' => 'Choose a valid Stripe environment.']);
        }
        $clientId = (string) config("payments.connections.stripe.{$environment}.client_id");
        $platformSecret = (string) config("payments.connections.stripe.{$environment}.platform_secret");
        if ($clientId === '' || $platformSecret === '') {
            throw ValidationException::withMessages(['connection' => 'Stripe Connect setup is required. Configure the application client ID, platform secret, and registered callback URL before connecting an account.']);
        }

        return ['client_id' => $clientId, 'platform_secret' => $platformSecret];
    }

    private function authorize(Organization $organization, User $actor): void
    {
        $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $actor->id)->where('status', 'active')->whereHas('organization', fn ($query) => $query->where('active', true))->first();
        abort_unless($membership?->hasCapability('payments.settings.manage'), 403);
    }

    private function hasOpenAttempts(PaymentProviderConfiguration $configuration): bool
    {
        return PaymentAttempt::query()->where('payment_provider_configuration_id', $configuration->id)->whereIn('status', ['open', 'processing', 'unknown'])->exists();
    }

    private function clearDefault(PaymentProviderConfiguration $configuration, User $actor): void
    {
        $settings = OrganizationBillingSetting::query()->where('organization_id', $configuration->organization_id)->lockForUpdate()->first();
        if ($settings?->default_payment_provider === 'stripe') {
            $settings->update(['default_payment_provider' => null, 'updated_by_id' => $actor->id]);
        }
    }

    private function fingerprint(string $accountId): string
    {
        return strtoupper(substr(hash('sha256', $accountId), 0, 12));
    }
}
