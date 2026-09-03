<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Domain\Commercial\LeadIntakeOrganizationResolver;
use App\Domain\Notifications\NewLeadSubmittedNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicLeadIntakeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadController extends Controller
{
    public function __invoke(
        PublicLeadIntakeRequest $request,
        LeadIntakeOrganizationResolver $organizations,
        LeadIntakeCreator $creator,
        NewLeadSubmittedNotifier $notifications,
    ): JsonResponse {
        $organization = $organizations->resolve();
        $smsConsent = $request->boolean('smsConsent');

        $lead = $creator->create($organization, [
            ...$request->normalizedLeadData(),
            'source' => 'website',
            'status' => 'received',
            'contact_consent_at' => now(),
            'contact_consent_ip' => $request->ip(),
            'contact_consent_version' => config('lead-intake.contact_consent_version'),
            'sms_consent_at' => $smsConsent ? now() : null,
            'sms_consent_ip' => $smsConsent ? $request->ip() : null,
            'sms_consent_version' => $smsConsent ? config('lead-intake.sms_consent_version') : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        try {
            $notifications->notify($lead);
        } catch (Throwable $exception) {
            Log::error('New lead notification publication failed.', [
                'organization_id' => $organization->id,
                'lead_id' => $lead->id,
                'failure_type' => class_basename($exception),
            ]);
        }

        return response()->json([
            'message' => 'Request received. NewDay Tech will follow up soon.',
        ], 201);
    }
}
