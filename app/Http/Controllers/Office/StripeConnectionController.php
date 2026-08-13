<?php

namespace App\Http\Controllers\Office;

use App\Domain\StripeConnectionWorkflow;
use App\Http\Controllers\Controller;
use App\Models\PaymentProviderConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StripeConnectionController extends Controller
{
    public function start(Request $request, StripeConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['environment' => ['required', Rule::in(['test', 'live'])]]);
        if ($data['environment'] === 'live' && ! app()->environment('production')) {
            return back()->withErrors(['environment' => 'Live Stripe connections may only be started in the production application environment.']);
        }
        $result = $workflow->start($request->attributes->get('organization'), $request->user(), $data['environment'], route('office.settings.billing.stripe.callback'));

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, StripeConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        if ($request->filled('error')) {
            $data = $request->validate(['state' => ['required', 'string', 'max:200']]);
            $workflow->denied($request->attributes->get('organization'), $request->user(), $data['state']);

            return redirect()->route('office.settings.billing.edit')->withErrors(['connection' => 'Stripe authorization was not completed.']);
        }
        $data = $request->validate(['state' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:2000']]);
        $configuration = $workflow->callback($request->attributes->get('organization'), $request->user(), $data['state'], $data['code']);

        return redirect()->route('office.settings.billing.edit')->with('status', 'Stripe account connected. Verify that payments are enabled, then enable Stripe when ready.')->with('connected_provider', $configuration->provider);
    }

    public function refresh(Request $request, StripeConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $workflow->refresh($this->configuration($request), $request->user());

        return back()->with('status', 'Stripe connection refreshed.');
    }

    public function disconnect(Request $request, StripeConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:50']]);
        $workflow->disconnect($this->configuration($request), $request->user(), $data['confirmation']);

        return back()->with('status', 'Stripe account disconnected.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->hasCapability('payments.settings.manage'), 403);
    }

    private function configuration(Request $request): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->forOrganization($request->attributes->get('organization')->id)->where('provider', 'stripe')->firstOrFail();
    }
}
