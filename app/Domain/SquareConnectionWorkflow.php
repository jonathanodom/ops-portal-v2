<?php

namespace App\Domain;

use App\Contracts\SquareConnectionClient;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\PaymentProviderConfiguration;
use App\Models\User;
use App\Support\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SquareConnectionWorkflow
{
    private const SCOPES = ['MERCHANT_PROFILE_READ', 'ORDERS_READ', 'ORDERS_WRITE', 'PAYMENTS_READ', 'PAYMENTS_WRITE'];

    public function __construct(
        private readonly SquareConnectionClient $square,
        private readonly PaymentAuthorizationStateWorkflow $authorizationStates,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{url: string, authorization_state: PaymentProviderAuthorizationState} */
    public function start(Organization $organization, User $actor, string $environment, string $redirectUri): array
    {
        $this->authorize($organization, $actor);
        $configuration = PaymentProviderConfiguration::query()->forOrganization($organization->id)->where('provider', 'square')->first();
        if ($configuration?->enabled || ($configuration && $this->hasOpenAttempts($configuration))) {
            throw ValidationException::withMessages(['connection' => 'Disable Square and resolve active checkout attempts before reconnecting it.']);
        }
        $application = $this->application($environment);
        $state = $this->authorizationStates->create($organization, $actor, 'square', $environment, '/office/settings/billing');
        $this->audit->record($organization, $actor, 'payments.square_connection_started', $state['authorization_state'], ['provider' => 'square', 'environment' => $environment]);

        return [
            'url' => $this->square->authorizationUrl($environment, $application['application_id'], $redirectUri, $state['state'], $state['code_challenge'], self::SCOPES),
            'authorization_state' => $state['authorization_state'],
        ];
    }

    public function callback(Organization $organization, User $actor, string $state, string $code, string $redirectUri): PaymentProviderConfiguration
    {
        $this->authorize($organization, $actor);
        $authorizationState = $this->authorizationStates->consume($organization, $actor, 'square', $state);
        $application = $this->application($authorizationState->environment);

        try {
            $tokens = $this->square->exchange(
                $authorizationState->environment,
                $application['application_id'],
                $application['application_secret'],
                $code,
                $redirectUri,
                (string) $authorizationState->pkce_verifier,
            );
            $profile = $this->square->profile($authorizationState->environment, $tokens['access_token']);
        } catch (Throwable) {
            $this->audit->record($organization, $actor, 'payments.square_connection_rejected', $authorizationState, ['provider' => 'square', 'environment' => $authorizationState->environment, 'failure_code' => 'provider_exchange_failed']);
            throw ValidationException::withMessages(['connection' => 'Square could not complete the account connection. Start the connection again.']);
        }

        if (! hash_equals($profile['merchant_id'], $tokens['merchant_id'])) {
            $this->audit->record($organization, $actor, 'payments.square_connection_rejected', $authorizationState, ['provider' => 'square', 'environment' => $authorizationState->environment, 'failure_code' => 'merchant_mismatch']);
            throw ValidationException::withMessages(['connection' => 'Square returned inconsistent merchant information. The account was not connected.']);
        }

        return DB::transaction(function () use ($organization, $actor, $authorizationState, $tokens, $profile): PaymentProviderConfiguration {
            $configuration = PaymentProviderConfiguration::query()->firstOrCreate(
                ['organization_id' => $organization->id, 'provider' => 'square'],
                ['public_id' => (string) Str::uuid(), 'environment' => $authorizationState->environment],
            );
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            if ($configuration->enabled || $this->hasOpenAttempts($configuration)) {
                throw ValidationException::withMessages(['connection' => 'Disable Square and resolve active checkout attempts before reconnecting it.']);
            }
            $location = $this->selectLocation($profile['locations'], $profile['main_location_id'], null);
            $configuration->forceFill([
                'environment' => $authorizationState->environment,
                'connection_method' => 'oauth',
                'api_secret' => null,
                'webhook_secret' => null,
                'credential_fingerprint' => $this->fingerprint($tokens['merchant_id']),
                'oauth_access_token' => $tokens['access_token'],
                'oauth_refresh_token' => $tokens['refresh_token'],
                'oauth_expires_at' => $this->parseExpiration($tokens['expires_at']),
                'external_account_id' => $tokens['merchant_id'],
                'external_account_name' => $profile['merchant_name'],
                'location_id' => $location['id'] ?? null,
                'location_name' => $location['name'] ?? null,
                'available_locations' => $profile['locations'],
                'connection_status' => 'connected',
                'connected_at' => now(),
                'connected_by_id' => $actor->id,
                'last_refreshed_at' => now(),
                'disconnected_at' => null,
                'last_test_code' => 'connected',
                'last_tested_at' => now(),
                'last_tested_by_id' => $actor->id,
                'enabled' => false,
                'updated_by_id' => $actor->id,
            ])->save();
            $this->audit->record($organization, $actor, 'payments.square_connected', $configuration, [
                'provider' => 'square', 'environment' => $configuration->environment, 'external_account_id' => $configuration->external_account_id,
                'location_id' => $configuration->location_id, 'changed_fields' => ['connection_method', 'connection_status', 'external_account_id', 'location_id'],
            ]);

            return $configuration->fresh();
        });
    }

    public function refresh(PaymentProviderConfiguration $configuration, User $actor): PaymentProviderConfiguration
    {
        $this->authorize($configuration->organization, $actor);
        if ($configuration->provider !== 'square' || $configuration->connection_method !== 'oauth' || blank($configuration->oauth_refresh_token)) {
            throw ValidationException::withMessages(['connection' => 'Square is not connected through hosted account authorization.']);
        }
        $application = $this->application($configuration->environment);
        try {
            $tokens = $this->square->refresh($configuration->environment, $application['application_id'], $application['application_secret'], (string) $configuration->oauth_refresh_token);
            $profile = $this->square->profile($configuration->environment, $tokens['access_token']);
        } catch (Throwable) {
            DB::transaction(function () use ($configuration, $actor): void {
                $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
                $configuration->update(['connection_status' => 'refresh_failed', 'enabled' => false, 'last_test_code' => 'square_refresh_failed', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id]);
                $settings = OrganizationBillingSetting::query()->where('organization_id', $configuration->organization_id)->lockForUpdate()->first();
                if ($settings?->default_payment_provider === 'square') {
                    $settings->update(['default_payment_provider' => null, 'updated_by_id' => $actor->id]);
                }
                $this->audit->record($configuration->organization, $actor, 'payments.square_refresh_failed', $configuration, ['provider' => 'square', 'failure_code' => 'provider_refresh_failed', 'changed_fields' => ['connection_status', 'enabled']]);
            });
            throw ValidationException::withMessages(['connection' => 'Square could not refresh the connection. Reconnect the account.']);
        }
        if (! hash_equals((string) $configuration->external_account_id, $profile['merchant_id'])) {
            throw ValidationException::withMessages(['connection' => 'Square returned a different merchant account. Reconnect the intended account.']);
        }

        return DB::transaction(function () use ($configuration, $actor, $tokens, $profile): PaymentProviderConfiguration {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $location = $this->selectLocation($profile['locations'], $profile['main_location_id'], $configuration->location_id);
            $configuration->forceFill([
                'oauth_access_token' => $tokens['access_token'], 'oauth_refresh_token' => $tokens['refresh_token'],
                'oauth_expires_at' => $this->parseExpiration($tokens['expires_at']), 'external_account_name' => $profile['merchant_name'],
                'location_id' => $location['id'] ?? null, 'location_name' => $location['name'] ?? null,
                'available_locations' => $profile['locations'], 'connection_status' => 'connected', 'last_refreshed_at' => now(),
                'last_test_code' => 'connected', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id, 'updated_by_id' => $actor->id,
            ])->save();
            $this->audit->record($configuration->organization, $actor, 'payments.square_refreshed', $configuration, ['provider' => 'square', 'external_account_id' => $configuration->external_account_id, 'location_id' => $configuration->location_id, 'changed_fields' => ['oauth_expires_at', 'location_id', 'connection_status']]);

            return $configuration->fresh();
        });
    }

    public function selectPaymentLocation(PaymentProviderConfiguration $configuration, User $actor, string $locationId): PaymentProviderConfiguration
    {
        $this->authorize($configuration->organization, $actor);
        if ($configuration->provider !== 'square' || $configuration->connection_method !== 'oauth') {
            throw ValidationException::withMessages(['location_id' => 'Connect Square before selecting a payment location.']);
        }
        $location = collect($configuration->available_locations ?? [])->firstWhere('id', $locationId);
        if (! $location) {
            throw ValidationException::withMessages(['location_id' => 'Choose an active location returned by the connected Square account.']);
        }
        $configuration->update(['location_id' => $location['id'], 'location_name' => $location['name'], 'updated_by_id' => $actor->id]);
        $this->audit->record($configuration->organization, $actor, 'payments.square_location_updated', $configuration, ['provider' => 'square', 'location_id' => $location['id'], 'changed_fields' => ['location_id']]);

        return $configuration->fresh();
    }

    public function disconnect(PaymentProviderConfiguration $configuration, User $actor, string $confirmation): void
    {
        $this->authorize($configuration->organization, $actor);
        if ($configuration->provider !== 'square' || $configuration->connection_method !== 'oauth' || strtoupper(trim($confirmation)) !== 'DISCONNECT SQUARE') {
            throw ValidationException::withMessages(['confirmation' => 'Enter DISCONNECT SQUARE to disconnect the account.']);
        }
        if ($configuration->enabled || $this->hasOpenAttempts($configuration)) {
            throw ValidationException::withMessages(['connection' => 'Disable Square and resolve active checkout attempts before disconnecting it.']);
        }
        $application = $this->application($configuration->environment);
        try {
            $this->square->revoke($configuration->environment, $application['application_id'], $application['application_secret'], (string) $configuration->oauth_access_token);
        } catch (Throwable) {
            throw ValidationException::withMessages(['connection' => 'Square could not confirm revocation. The local connection was retained for a safe retry.']);
        }

        DB::transaction(function () use ($configuration, $actor): void {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $settings = OrganizationBillingSetting::query()->where('organization_id', $configuration->organization_id)->lockForUpdate()->first();
            if ($settings?->default_payment_provider === 'square') {
                $settings->update(['default_payment_provider' => null, 'updated_by_id' => $actor->id]);
            }
            $configuration->forceFill([
                'oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null,
                'external_account_id' => null, 'external_account_name' => null, 'location_id' => null, 'location_name' => null,
                'available_locations' => null, 'connection_status' => 'disconnected', 'connected_at' => null, 'connected_by_id' => null,
                'last_refreshed_at' => null, 'disconnected_at' => now(), 'last_test_code' => null, 'last_tested_at' => null,
                'last_tested_by_id' => null, 'enabled' => false, 'updated_by_id' => $actor->id,
            ])->save();
            $this->audit->record($configuration->organization, $actor, 'payments.square_disconnected', $configuration, ['provider' => 'square', 'environment' => $configuration->environment, 'changed_fields' => ['connection_status', 'external_account_id', 'location_id']]);
        });
    }

    /** @return array{application_id: string, application_secret: string} */
    private function application(string $environment): array
    {
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw ValidationException::withMessages(['environment' => 'Choose a valid Square environment.']);
        }
        $applicationId = (string) config("payments.connections.square.{$environment}.application_id");
        $applicationSecret = (string) config("payments.connections.square.{$environment}.application_secret");
        if ($applicationId === '' || $applicationSecret === '') {
            throw ValidationException::withMessages(['connection' => 'Square application authorization is not configured for this environment.']);
        }

        return ['application_id' => $applicationId, 'application_secret' => $applicationSecret];
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

    /** @param array<int, array{id: string, name: string}> $locations @return array{id: string, name: string}|null */
    private function selectLocation(array $locations, ?string $mainLocationId, ?string $currentLocationId): ?array
    {
        return collect($locations)->firstWhere('id', $currentLocationId)
            ?? collect($locations)->firstWhere('id', $mainLocationId)
            ?? ($locations[0] ?? null);
    }

    private function parseExpiration(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value)->utc() : null;
    }

    private function fingerprint(string $merchantId): string
    {
        return strtoupper(substr(hash('sha256', $merchantId), 0, 12));
    }
}
