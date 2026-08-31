<?php

namespace App\Http\Controllers\Api\Public\V1;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Domain\Commercial\LeadIntakeOrganizationResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicLeadIntakeRequest;
use Illuminate\Http\JsonResponse;

class LeadController extends Controller
{
    public function __invoke(
        PublicLeadIntakeRequest $request,
        LeadIntakeOrganizationResolver $organizations,
        LeadIntakeCreator $creator,
    ): JsonResponse {
        $organization = $organizations->resolve();
        $smsConsent = $request->boolean('smsConsent');

        $creator->create($organization, [
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

        return response()->json([
            'message' => 'Request received. NewDay Tech will follow up soon.',
        ], 201);
    }
}
