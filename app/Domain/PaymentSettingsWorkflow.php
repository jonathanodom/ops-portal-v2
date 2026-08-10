<?php

namespace App\Domain;

use App\Models\Organization;
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
            ['public_id' => (string) Str::uuid(), 'environment' => $provider === 'square' ? 'sandbox' : 'test'],
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
                $configuration->api_secret = $values['api_secret'];
                $configuration->credential_fingerprint = strtoupper(substr(hash('sha256', (string) $values['api_secret']), 0, 12));
                $configuration->connection_status = 'untested';
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
            $configuration->update(['connection_status' => 'connected', 'external_account_id' => $result['account_id'], 'last_test_code' => 'connected', 'last_tested_at' => now(), 'last_tested_by_id' => $actor->id]);
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
        $configuration->update(['enabled' => $enabled, 'updated_by_id' => $actor->id]);
        $this->audit->record($configuration->organization, $actor, $enabled ? 'payments.provider_enabled' : 'payments.provider_disabled', $configuration, ['provider' => $configuration->provider, 'environment' => $configuration->environment]);

        return $configuration;
    }

    public function clear(PaymentProviderConfiguration $configuration, User $actor, string $confirmation): void
    {
        if ($configuration->enabled || strtoupper(trim($confirmation)) !== 'CLEAR '.strtoupper($configuration->provider)) {
            throw ValidationException::withMessages(['confirmation' => 'Disable the provider and enter the required confirmation text.']);
        }
        if (PaymentAttempt::query()->where('payment_provider_configuration_id', $configuration->id)->whereIn('status', ['open', 'processing', 'unknown'])->exists()) {
            throw ValidationException::withMessages(['provider' => 'Expire or reconcile active attempts before clearing credentials.']);
        }
        $configuration->update(['api_secret' => null, 'webhook_secret' => null, 'credential_fingerprint' => null, 'connection_status' => 'untested', 'external_account_id' => null, 'last_test_code' => null, 'updated_by_id' => $actor->id]);
        $this->audit->record($configuration->organization, $actor, 'payments.provider_credentials_cleared', $configuration, ['provider' => $configuration->provider, 'changed_fields' => ['api_secret', 'webhook_secret']]);
    }

    private function isLive(PaymentProviderConfiguration $configuration): bool
    {
        return in_array($configuration->environment, ['production', 'live'], true);
    }
}
