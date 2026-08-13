<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\PaymentAttempt;
use App\Models\PaymentProviderConfiguration;
use App\Models\User;
use App\Payments\PaymentProviderResolver;
use App\Support\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentSettingsWorkflow
{
    public function __construct(private readonly PaymentProviderResolver $providers, private readonly AuditRecorder $audit) {}

    public function configuration(Organization $organization, string $provider): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'provider' => $provider],
            ['public_id' => (string) Str::uuid(), 'environment' => $provider === 'square' ? 'sandbox' : 'test', 'connection_method' => 'legacy_credentials'],
        );
    }

    /** @param array<string, mixed> $values */
    public function save(PaymentProviderConfiguration $configuration, User $actor, array $values): PaymentProviderConfiguration
    {
        return DB::transaction(function () use ($configuration, $actor, $values): PaymentProviderConfiguration {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $changed = ['environment'];
            $configuration->environment = $values['environment'];
            $configuration->location_id = $configuration->provider === 'square' ? ($values['location_id'] ?? null) : null;
            if (filled($values['api_secret'] ?? null)) {
                $configuration->connection_method = 'legacy_credentials';
                $configuration->api_secret = $values['api_secret'];
                $configuration->oauth_access_token = null;
                $configuration->oauth_refresh_token = null;
                $configuration->oauth_expires_at = null;
                $configuration->credential_fingerprint = strtoupper(substr(hash('sha256', (string) $values['api_secret']), 0, 12));
                $configuration->connection_status = 'untested';
                $configuration->external_account_id = null;
                $configuration->external_account_name = null;
                $configuration->connected_at = null;
                $configuration->connected_by_id = null;
                $configuration->last_refreshed_at = null;
                $configuration->disconnected_at = now();
                $configuration->enabled = false;
                $changed[] = 'api_secret';
            }
            if (filled($values['webhook_secret'] ?? null)) {
                $configuration->webhook_secret = $values['webhook_secret'];
                $changed[] = 'webhook_secret';
            }
            if ($configuration->provider === 'square') {
                $changed[] = 'location_id';
            }
            $configuration->updated_by_id = $actor->id;
            $configuration->save();
            $this->audit->record($configuration->organization, $actor, 'payments.provider_credentials_updated', $configuration, ['provider' => $configuration->provider, 'changed_fields' => $changed, 'fingerprint' => $configuration->credential_fingerprint]);

            return $configuration;
        });
    }

    public function test(PaymentProviderConfiguration $configuration, User $actor): PaymentProviderConfiguration
    {
        try {
            $result = $this->providers->resolve($configuration)->testConnection($configuration);
            $configuration->update(['connection_status' => 'connected', 'external_account_id' => $result['account_id'], 'external_account_name' => $result['account_name'] ?? null, 'connected_at' => $configuration->connected_at ?? now(), 'connected_by_id' => $configuration->connected_by_id ?? $actor->id, 'disconnected_at' => null, 'last_test_code' => 'connected', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id]);
        } catch (Throwable) {
            $configuration->update(['connection_status' => 'failed', 'external_account_id' => null, 'last_test_code' => 'provider_connection_failed', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id, 'enabled' => false]);
            throw ValidationException::withMessages(['provider' => 'The provider connection test failed. Verify the environment and credentials.']);
        }
        $this->audit->record($configuration->organization, $actor, 'payments.provider_connection_tested', $configuration, ['provider' => $configuration->provider, 'status' => $configuration->connection_status]);

        return $configuration;
    }

    public function setEnabled(PaymentProviderConfiguration $configuration, User $actor, bool $enabled, bool $confirmLive): PaymentProviderConfiguration
    {
        if ($enabled && $configuration->connection_status !== 'connected') {
            throw ValidationException::withMessages(['enabled' => 'Test the provider connection successfully before enabling it.']);
        }
        if ($enabled && $this->isLive($configuration)) {
            if (! app()->environment('production') || ! str_starts_with((string) config('app.url'), 'https://') || ! config('payments.live_enabled') || ! $confirmLive) {
                throw ValidationException::withMessages(['enabled' => 'Live payments require production, HTTPS, PAYMENTS_LIVE_ENABLED=true, and explicit confirmation.']);
            }
        }
        DB::transaction(function () use ($configuration, $actor, $enabled): void {
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->findOrFail($configuration->id);
            $configuration->update(['enabled' => $enabled, 'updated_by_id' => $actor->id]);
            if (! $enabled) {
                $settings = OrganizationBillingSetting::query()->where('organization_id', $configuration->organization_id)->lockForUpdate()->first();
                if ($settings?->default_payment_provider === $configuration->provider) {
                    $settings->update(['default_payment_provider' => null, 'updated_by_id' => $actor->id]);
                    $this->audit->record($configuration->organization, $actor, 'payments.default_provider_cleared', $settings, ['provider' => $configuration->provider, 'changed_fields' => ['default_payment_provider']]);
                }
            }
            $this->audit->record($configuration->organization, $actor, $enabled ? 'payments.provider_enabled' : 'payments.provider_disabled', $configuration, ['provider' => $configuration->provider, 'environment' => $configuration->environment]);
        });

        return $configuration->fresh();
    }

    public function setDefaultProvider(Organization $organization, User $actor, ?string $provider): OrganizationBillingSetting
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('active', true))
            ->first();
        abort_unless($membership?->hasCapability('payments.settings.manage'), 403);

        return DB::transaction(function () use ($organization, $actor, $provider): OrganizationBillingSetting {
            $settings = OrganizationBillingSetting::query()->firstOrCreate(
                ['organization_id' => $organization->id],
                ['default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt'],
            );
            $settings = OrganizationBillingSetting::query()->lockForUpdate()->findOrFail($settings->id);
            if ($provider !== null) {
                if (! in_array($provider, ['square', 'stripe'], true)) {
                    throw ValidationException::withMessages(['default_payment_provider' => 'Choose Square, Stripe, or no default provider.']);
                }
                $configuration = PaymentProviderConfiguration::query()
                    ->forOrganization($organization->id)
                    ->where('provider', $provider)
                    ->lockForUpdate()
                    ->first();
                if (! $configuration?->isReady()) {
                    throw ValidationException::withMessages(['default_payment_provider' => 'Only a connected and enabled provider may be the organization default.']);
                }
            }
            $settings->update(['default_payment_provider' => $provider, 'updated_by_id' => $actor->id]);
            $this->audit->record($organization, $actor, 'payments.default_provider_updated', $settings, ['provider' => $provider, 'changed_fields' => ['default_payment_provider']]);

            return $settings->fresh();
        });
    }

    public function clear(PaymentProviderConfiguration $configuration, User $actor, string $confirmation): void
    {
        if ($configuration->enabled || strtoupper(trim($confirmation)) !== 'CLEAR '.strtoupper($configuration->provider)) {
            throw ValidationException::withMessages(['confirmation' => 'Disable the provider and enter the required confirmation text.']);
        }
        if (PaymentAttempt::query()->where('payment_provider_configuration_id', $configuration->id)->whereIn('status', ['open', 'processing', 'unknown'])->exists()) {
            throw ValidationException::withMessages(['provider' => 'Expire or reconcile active attempts before clearing credentials.']);
        }
        $configuration->update(['api_secret' => null, 'webhook_secret' => null, 'oauth_access_token' => null, 'oauth_refresh_token' => null, 'oauth_expires_at' => null, 'credential_fingerprint' => null, 'connection_status' => 'untested', 'external_account_id' => null, 'external_account_name' => null, 'connected_at' => null, 'connected_by_id' => null, 'last_refreshed_at' => null, 'disconnected_at' => now(), 'last_test_code' => null, 'updated_by_id' => $actor->id]);
        $this->audit->record($configuration->organization, $actor, 'payments.provider_credentials_cleared', $configuration, ['provider' => $configuration->provider, 'changed_fields' => ['api_secret', 'webhook_secret']]);
    }

    private function isLive(PaymentProviderConfiguration $configuration): bool
    {
        return in_array($configuration->environment, ['production', 'live'], true);
    }
}
