<?php

namespace App\Http\Controllers\Office;

use App\Domain\PaymentSettingsWorkflow;
use App\Http\Controllers\Controller;
use App\Models\PaymentProviderConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentSettingsController extends Controller
{
    public function update(Request $request, string $provider, PaymentSettingsWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $provider = $this->provider($provider);
        abort_if(in_array($provider, ['square', 'stripe'], true), 404);
        $configuration = $workflow->configuration($request->attributes->get('organization'), $provider);
        $environments = $provider === 'square' ? ['sandbox', 'production'] : ['test', 'live'];
        $data = $request->validate([
            'environment' => ['required', Rule::in($environments)],
            'api_secret' => ['nullable', 'string', 'min:8', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'min:8', 'max:2000'],
            'location_id' => [$provider === 'square' ? 'required' : 'nullable', 'string', 'max:120'],
        ]);
        $workflow->save($configuration, $request->user(), $data);

        return back()->with('status', ucfirst($provider).' credentials saved securely. Secret values are not displayed.');
    }

    public function test(Request $request, string $provider, PaymentSettingsWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $configuration = $this->configuration($request, $this->provider($provider));
        $workflow->test($configuration, $request->user());

        return back()->with('status', ucfirst($configuration->provider).' connection verified.');
    }

    public function toggle(Request $request, string $provider, PaymentSettingsWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $configuration = $this->configuration($request, $this->provider($provider));
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'confirm_live' => ['nullable', 'accepted']]);
        $workflow->setEnabled($configuration, $request->user(), (bool) $data['enabled'], $request->boolean('confirm_live'));

        return back()->with('status', ucfirst($configuration->provider).' '.($data['enabled'] ? 'enabled' : 'disabled').'.');
    }

    public function clear(Request $request, string $provider, PaymentSettingsWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $configuration = $this->configuration($request, $this->provider($provider));
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:50']]);
        $workflow->clear($configuration, $request->user(), $data['confirmation']);

        return back()->with('status', ucfirst($configuration->provider).' credentials cleared.');
    }

    public function defaultProvider(Request $request, PaymentSettingsWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['default_payment_provider' => ['nullable', Rule::in(['square', 'stripe'])]]);
        $provider = filled($data['default_payment_provider'] ?? null) ? $data['default_payment_provider'] : null;
        $workflow->setDefaultProvider($request->attributes->get('organization'), $request->user(), $provider);

        return back()->with('status', $provider ? ucfirst($provider).' is now the default electronic payment provider.' : 'The default electronic payment provider was cleared.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->hasCapability('payments.settings.manage'), 403);
    }

    private function provider(string $provider): string
    {
        abort_unless(in_array($provider, ['square', 'stripe'], true), 404);

        return $provider;
    }

    private function configuration(Request $request, string $provider): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->forOrganization($request->attributes->get('organization')->id)->where('provider', $provider)->firstOrFail();
    }
}
