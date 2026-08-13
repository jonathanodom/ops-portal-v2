<?php

namespace App\Http\Controllers\Office;

use App\Domain\SquareConnectionWorkflow;
use App\Http\Controllers\Controller;
use App\Models\PaymentProviderConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SquareConnectionController extends Controller
{
    public function start(Request $request, SquareConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['environment' => ['required', Rule::in(['sandbox', 'production'])]]);
        if ($data['environment'] === 'production' && ! app()->environment('production')) {
            return back()->withErrors(['environment' => 'Production Square connections may only be started in the production application environment.']);
        }
        $result = $workflow->start($request->attributes->get('organization'), $request->user(), $data['environment'], route('office.settings.billing.square.callback'));

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, SquareConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        if ($request->filled('error')) {
            return redirect()->route('office.settings.billing.edit')->withErrors(['connection' => 'Square authorization was not completed.']);
        }
        $data = $request->validate(['state' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:2000']]);
        $configuration = $workflow->callback($request->attributes->get('organization'), $request->user(), $data['state'], $data['code'], route('office.settings.billing.square.callback'));

        return redirect()->route('office.settings.billing.edit')->with('status', 'Square account connected. Review the payment location, then enable Square when ready.')->with('connected_provider', $configuration->provider);
    }

    public function refresh(Request $request, SquareConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $workflow->refresh($this->configuration($request), $request->user());

        return back()->with('status', 'Square connection refreshed.');
    }

    public function location(Request $request, SquareConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['location_id' => ['required', 'string', 'max:120']]);
        $workflow->selectPaymentLocation($this->configuration($request), $request->user(), $data['location_id']);

        return back()->with('status', 'Square payment location updated.');
    }

    public function disconnect(Request $request, SquareConnectionWorkflow $workflow): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:50']]);
        $workflow->disconnect($this->configuration($request), $request->user(), $data['confirmation']);

        return back()->with('status', 'Square account disconnected.');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->attributes->get('membership')->hasCapability('payments.settings.manage'), 403);
    }

    private function configuration(Request $request): PaymentProviderConfiguration
    {
        return PaymentProviderConfiguration::query()->forOrganization($request->attributes->get('organization')->id)->where('provider', 'square')->firstOrFail();
    }
}
