<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicLeadIntakeCorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'cors.allowed_origins' => [
                'https://newdaytech.net',
                'https://www.newdaytech.net',
            ],
            'lead-intake.organization_slug' => null,
            'lead-intake.turnstile_secret' => null,
        ]);
    }

    #[DataProvider('approvedOrigins')]
    public function test_approved_marketing_origins_can_submit_leads(string $origin): void
    {
        Organization::factory()->create();

        $this->withHeader('Origin', $origin)
            ->postJson(route('api.public.v1.leads.store'), $this->validPayload())
            ->assertCreated()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public static function approvedOrigins(): array
    {
        return [
            'apex domain' => ['https://newdaytech.net'],
            'www domain' => ['https://www.newdaytech.net'],
        ];
    }

    public function test_unapproved_origin_receives_no_cors_permission(): void
    {
        Organization::factory()->create();

        $this->withHeader('Origin', 'https://attacker.example')
            ->postJson(route('api.public.v1.leads.store'), $this->validPayload())
            ->assertCreated()
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_approved_preflight_is_limited_to_required_method_and_headers(): void
    {
        $this->call('OPTIONS', '/api/public/v1/leads', server: [
            'HTTP_ORIGIN' => 'https://newdaytech.net',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type, accept',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://newdaytech.net')
            ->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'accept, content-type')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_jarvis_api_does_not_inherit_marketing_site_cors(): void
    {
        $this->call('OPTIONS', '/api/v1/me', server: [
            'HTTP_ORIGIN' => 'https://newdaytech.net',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function validPayload(): array
    {
        return [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'phone' => '940-555-1212',
            'email' => 'jane@example.test',
            'customerType' => 'Residential',
            'zip' => '76450',
            'company' => null,
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
