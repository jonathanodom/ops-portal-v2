<?php

namespace Tests\Feature;

use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicLeadIntakeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-30 18:30:00 UTC');
        config([
            'cache.default' => 'array',
            'lead-intake.organization_slug' => null,
            'lead-intake.turnstile_secret' => null,
            'lead-intake.contact_consent_version' => 'contact-test-v1',
            'lead-intake.sms_consent_version' => 'sms-test-v1',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_website_payload_is_normalized_attributed_and_stored_without_internal_response_data(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->postLead([
            'organization_id' => 999999,
            'organizationSlug' => 'forged',
            'source' => 'manual',
            'status' => 'spam',
        ]);

        $response->assertCreated()->assertExactJson([
            'message' => 'Request received. NewDay Tech will follow up soon.',
        ]);

        $intake = CommercialLeadIntake::query()->sole();
        $this->assertSame($organization->id, $intake->organization_id);
        $this->assertSame('website', $intake->source);
        $this->assertSame('received', $intake->status);
        $this->assertSame('Jane', $intake->first_name);
        $this->assertSame('Smith', $intake->last_name);
        $this->assertSame('Individual', $intake->customer_type);
        $this->assertNull($intake->company);
        $this->assertSame('Wi-Fi & Networking', $intake->service_interest);
        $this->assertSame('google', $intake->utm_source);
        $this->assertSame('wifi', $intake->utm_campaign);
        $this->assertSame('https://www.google.com/', $intake->referrer);
        $this->assertSame('198.51.100.10', $intake->ip_address);
        $this->assertSame('198.51.100.10', $intake->contact_consent_ip);
        $this->assertSame('contact-test-v1', $intake->contact_consent_version);
        $this->assertNull($intake->sms_consent_at);
        $this->assertNull($intake->sms_consent_ip);
        $this->assertFalse($intake->payload['sms_consent']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('opportunities', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_public_payloads_are_rejected_without_storage(array $overrides, string $errorField): void
    {
        Organization::factory()->create();

        $this->postLead($overrides)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath("error.details.{$errorField}.0", fn (string $message): bool => $message !== '');

        $this->assertDatabaseCount('commercial_lead_intakes', 0);
    }

    public static function invalidPayloads(): array
    {
        return [
            'business company required' => [['customerType' => 'Business', 'company' => null], 'company'],
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'invalid ZIP' => [['zip' => 'ABC'], 'zip'],
            'invalid service interest' => [['serviceInterest' => 'Unconfigured service'], 'serviceInterest'],
            'general consent required' => [['consent' => false], 'consent'],
            'honeypot rejected generically' => [['website' => 'https://spam.example'], 'website'],
        ];
    }

    public function test_sms_consent_is_separate_from_text_contact_preference(): void
    {
        Organization::factory()->create();

        $this->postLead(['preferredContact' => 'Text', 'smsConsent' => false], '198.51.100.11')->assertCreated();
        $this->postLead(['preferredContact' => 'Text', 'smsConsent' => true], '198.51.100.12')->assertCreated();

        [$withoutSms, $withSms] = CommercialLeadIntake::query()->orderBy('id')->get();
        $this->assertNull($withoutSms->sms_consent_at);
        $this->assertNull($withoutSms->sms_consent_ip);
        $this->assertNull($withoutSms->sms_consent_version);
        $this->assertNotNull($withSms->sms_consent_at);
        $this->assertSame('198.51.100.12', $withSms->sms_consent_ip);
        $this->assertSame('sms-test-v1', $withSms->sms_consent_version);
        $this->assertTrue($withSms->payload['sms_consent']);
    }

    public function test_turnstile_is_optional_when_disabled_and_verified_without_persisting_the_token_when_enabled(): void
    {
        Organization::factory()->create();

        $this->postLead([], '198.51.100.20')->assertCreated();
        config(['lead-intake.turnstile_secret' => 'turnstile-test-secret']);

        $this->postLead([], '198.51.100.21')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.turnstileToken.0', 'Please complete the verification check.');

        Http::fake(fn (Request $request) => Http::response([
            'success' => $request['response'] === 'successful-token',
        ]));
        $this->postLead(['turnstileToken' => 'failed-token'], '198.51.100.22')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.turnstileToken.0', 'Please complete the verification check.');

        $this->postLead(['turnstileToken' => 'successful-token'], '198.51.100.23')->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->isForm()
            && $request['secret'] === 'turnstile-test-secret'
            && $request['response'] === 'successful-token'
            && $request['remoteip'] === '198.51.100.23');
        $payloads = CommercialLeadIntake::query()->pluck('payload')->map(fn (array $payload): string => json_encode($payload))->implode(' ');
        $this->assertStringNotContainsString('successful-token', $payloads);
        $this->assertStringNotContainsString('failed-token', $payloads);
    }

    public function test_configured_organization_is_authoritative_and_ambiguous_fallback_fails_safely(): void
    {
        $destination = Organization::factory()->create(['slug' => 'lead-destination']);
        $other = Organization::factory()->create(['slug' => 'other-organization']);
        config(['lead-intake.organization_slug' => $destination->slug]);

        $this->postLead([
            'organization_id' => $other->id,
            'organizationSlug' => $other->slug,
        ], '198.51.100.30')->assertCreated();
        $this->assertSame($destination->id, CommercialLeadIntake::query()->sole()->organization_id);

        config(['lead-intake.organization_slug' => null]);
        $this->postLead([], '198.51.100.31')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonMissing(['lead-destination', 'other-organization']);
        $this->assertDatabaseCount('commercial_lead_intakes', 1);
    }

    public function test_public_route_is_separate_from_authentication_and_rate_limits_by_ip(): void
    {
        Organization::factory()->create();
        config(['lead-intake.rate_limit_per_minute' => 2]);

        $route = Route::getRoutes()->getByName('api.public.v1.leads.store');
        $this->assertNotNull($route);
        $this->assertSame('api/public/v1/leads', $route->uri());
        $this->assertContains('api.request.size', $route->gatherMiddleware());
        $this->assertContains('throttle:public-leads', $route->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());

        $this->postLead([], '198.51.100.40')->assertCreated();
        $this->postLead([], '198.51.100.40')->assertCreated();
        $this->postLead([], '198.51.100.40')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited');
        $this->assertDatabaseCount('commercial_lead_intakes', 2);
    }

    private function postLead(array $overrides = [], string $ip = '198.51.100.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
            route('api.public.v1.leads.store'),
            array_replace($this->validPayload(), $overrides),
        );
    }

    private function validPayload(): array
    {
        return [
            'firstName' => "  Ja\x00ne  ",
            'lastName' => ' Smith ',
            'phone' => '940-555-1212',
            'email' => 'jane@example.test',
            'customerType' => 'Residential',
            'zip' => '76450',
            'company' => 'Must be removed for an individual',
            'serviceInterest' => 'Wi-Fi & Networking',
            'selectedPlan' => null,
            'preferredContact' => 'Phone',
            'timeline' => 'Within 30 days',
            'details' => 'Need coverage across the property.',
            'originatingPage' => '/about-contact/',
            'utmSource' => 'google',
            'utmMedium' => 'cpc',
            'utmCampaign' => 'wifi',
            'utmTerm' => null,
            'utmContent' => null,
            'referrer' => 'https://www.google.com/',
            'website' => '',
            'consent' => true,
            'smsConsent' => false,
        ];
    }
}
