<?php

namespace App\Http\Controllers;

use App\Domain\PaymentWorkflow;
use App\Models\PaymentProviderConfiguration;
use App\Payments\PaymentProviderResolver;
use App\Support\IncidentRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, string $configuration, PaymentProviderResolver $providers, PaymentWorkflow $workflow, IncidentRecorder $incidents): Response
    {
        $configuration = PaymentProviderConfiguration::query()->where('public_id', $configuration)->where('provider', $provider)->firstOrFail();
        $raw = $request->getContent();
        $headers = ['stripe-signature' => $request->header('Stripe-Signature'), 'x-square-hmacsha256-signature' => $request->header('X-Square-Hmacsha256-Signature'), 'x-fake-signature' => $request->header('X-Fake-Signature')];
        try {
            $event = $providers->resolve($configuration)->parseWebhook($configuration, $raw, $headers, $request->fullUrl());
        } catch (Throwable) {
            $incidents->record($configuration->organization, null, 'payment_webhook_invalid', 'warning', $configuration, ['reason_code' => 'signature_or_payload_invalid', 'provider' => $provider]);
            abort(400, 'Invalid webhook.');
        }
        $workflow->processWebhook($configuration, $event, hash('sha256', $raw));

        return response('', 200);
    }
}
