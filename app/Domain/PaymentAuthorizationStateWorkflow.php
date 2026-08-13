<?php

namespace App\Domain;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PaymentProviderAuthorizationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentAuthorizationStateWorkflow
{
    /** @return array{authorization_state: PaymentProviderAuthorizationState, state: string, code_verifier: string, code_challenge: string} */
    public function create(Organization $organization, User $actor, string $provider, string $environment, ?string $returnPath = null): array
    {
        $this->authorize($organization, $actor);
        $this->validateContext($provider, $environment, $returnPath);
        $state = Str::random(64);
        $verifier = Str::random(96);
        $authorizationState = PaymentProviderAuthorizationState::query()->create([
            'organization_id' => $organization->id,
            'provider' => $provider,
            'actor_id' => $actor->id,
            'state_hash' => hash('sha256', $state),
            'pkce_verifier' => $verifier,
            'environment' => $environment,
            'return_path' => $returnPath,
            'expires_at' => now()->addMinutes(10),
        ]);

        return [
            'authorization_state' => $authorizationState,
            'state' => $state,
            'code_verifier' => $verifier,
            'code_challenge' => $this->base64UrlEncode(hash('sha256', $verifier, true)),
        ];
    }

    public function consume(Organization $organization, User $actor, string $provider, string $state): PaymentProviderAuthorizationState
    {
        $this->authorize($organization, $actor);

        return DB::transaction(function () use ($organization, $actor, $provider, $state): PaymentProviderAuthorizationState {
            $authorizationState = PaymentProviderAuthorizationState::query()
                ->where('organization_id', $organization->id)
                ->where('actor_id', $actor->id)
                ->where('provider', $provider)
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->first();

            if (! $authorizationState || $authorizationState->consumed_at || $authorizationState->expires_at->isPast()) {
                throw ValidationException::withMessages(['connection' => 'The payment-provider authorization is invalid or expired. Start the connection again.']);
            }

            $authorizationState->update(['consumed_at' => now()]);

            return $authorizationState->fresh();
        });
    }

    private function authorize(Organization $organization, User $actor): void
    {
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('active', true))
            ->first();

        abort_unless($membership?->hasCapability('payments.settings.manage'), 403);
    }

    private function validateContext(string $provider, string $environment, ?string $returnPath): void
    {
        if (! in_array($provider, ['square', 'stripe'], true)) {
            throw ValidationException::withMessages(['provider' => 'The payment provider is not supported.']);
        }
        $allowedEnvironments = $provider === 'square' ? ['sandbox', 'production'] : ['test', 'live'];
        if (! in_array($environment, $allowedEnvironments, true)) {
            throw ValidationException::withMessages(['environment' => 'The payment-provider environment is invalid.']);
        }
        if ($returnPath !== null && (! str_starts_with($returnPath, '/') || str_starts_with($returnPath, '//') || mb_strlen($returnPath) > 500)) {
            throw ValidationException::withMessages(['return_path' => 'The return path must be a local portal path.']);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
