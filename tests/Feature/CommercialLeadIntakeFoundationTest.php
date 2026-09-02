<?php

namespace Tests\Feature;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Models\CommercialLeadIntake;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommercialLeadIntakeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_creator_persists_scoped_source_identity_attribution_and_separate_consent_evidence(): void
    {
        Carbon::setTestNow('2026-08-30 14:15:16 UTC');
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $intake = app(LeadIntakeCreator::class)->create($organization, [
            'organization_id' => $otherOrganization->id,
            'source' => 'website',
            'engagement_status' => 'qualified',
            'first_name' => 'Jordan',
            'last_name' => 'Rivera',
            'phone' => '555-010-2200',
            'email' => 'jordan@example.test',
            'customer_type' => 'Business',
            'zip' => '75001',
            'company' => 'Example Systems',
            'service_interest' => 'Managed IT',
            'selected_plan' => 'Business Care',
            'preferred_contact' => 'Text',
            'timeline' => 'Within 30 days',
            'details' => 'Needs an office technology assessment.',
            'originating_page' => '/business-services',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'north-texas-it',
            'utm_term' => 'business support',
            'utm_content' => 'service-card',
            'referrer' => 'https://www.google.com/',
            'contact_consent_at' => now(),
            'contact_consent_ip' => '192.0.2.10',
            'contact_consent_version' => 'contact-v1',
            'sms_consent_at' => null,
            'sms_consent_ip' => '192.0.2.10',
            'sms_consent_version' => 'must-not-be-inferred',
            'ip_address' => '192.0.2.10',
            'user_agent' => 'Focused test agent',
        ]);

        $this->assertSame($organization->id, $intake->organization_id);
        $this->assertTrue($intake->organization->is($organization));
        $this->assertSame('received', $intake->status);
        $this->assertSame('website', $intake->source);
        $this->assertNull($intake->engagement_status);
        $this->assertSame('new', $intake->engagementStatus());
        $this->assertSame('Jordan', $intake->first_name);
        $this->assertSame('jordan@example.test', $intake->email);
        $this->assertSame('north-texas-it', $intake->utm_campaign);
        $this->assertSame('/business-services', $intake->originating_page);
        $this->assertNotNull($intake->contact_consent_at);
        $this->assertSame('192.0.2.10', $intake->contact_consent_ip);
        $this->assertNull($intake->sms_consent_at);
        $this->assertNull($intake->sms_consent_ip);
        $this->assertNull($intake->sms_consent_version);
        $this->assertFalse($intake->payload['sms_consent']);
        $this->assertTrue($intake->payload['contact_consent']);
        $this->assertSame('Text', $intake->payload['preferred_contact']);
        $this->assertSame('2026-08-30 14:15:16', $intake->received_at->format('Y-m-d H:i:s'));
        $this->assertNull($intake->opportunity_id);
        $this->assertNull($intake->opportunity);
        $this->assertIsArray($intake->payload);
        $this->assertCount(1, $organization->commercialLeadIntakes);
    }

    public function test_payload_hash_is_deterministic_for_the_same_normalized_values(): void
    {
        $organization = Organization::factory()->create();
        $creator = app(LeadIntakeCreator::class);
        $values = $this->normalizedValues();

        $first = $creator->create($organization, $values);
        $second = $creator->create($organization, array_reverse($values, true));

        $this->assertSame($first->payload, $second->payload);
        $this->assertSame($first->payload_sha256, $second->payload_sha256);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->payload_sha256);
    }

    public function test_future_opportunity_provenance_and_organization_scope_are_available_without_conversion(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $creator = app(LeadIntakeCreator::class);
        $intake = $creator->create($organization, $this->normalizedValues());
        $otherIntake = $creator->create($otherOrganization, $this->normalizedValues());
        $customer = Customer::factory()->for($organization)->create();
        $stage = OpportunityStage::query()->create([
            'organization_id' => $organization->id,
            'name' => 'New',
            'semantic_kind' => 'new',
            'default_probability_bps' => 1000,
        ]);
        $converter = User::factory()->create();
        $opportunity = Opportunity::query()->create([
            'organization_id' => $organization->id,
            'opportunity_number' => 'OPP-2026-0001',
            'customer_id' => $customer->id,
            'stage_id' => $stage->id,
            'title' => 'Future conversion target',
            'priority' => 'normal',
        ]);

        $intake->update([
            'status' => 'converted',
            'opportunity_id' => $opportunity->id,
            'converted_at' => now(),
            'converted_by_id' => $converter->id,
        ]);
        $intake->refresh();

        $this->assertTrue($intake->opportunity->is($opportunity));
        $this->assertTrue($intake->convertedBy->is($converter));
        $this->assertSame([$intake->id], CommercialLeadIntake::query()->forOrganization($organization->id)->pluck('id')->all());
        $this->assertSame([$otherIntake->id], CommercialLeadIntake::query()->forOrganization($otherOrganization->id)->pluck('id')->all());
    }

    private function normalizedValues(): array
    {
        return [
            'source' => 'manual',
            'first_name' => 'Casey',
            'last_name' => 'Morgan',
            'phone' => '555-010-3300',
            'email' => 'casey@example.test',
            'customer_type' => 'Individual',
            'zip' => '75002',
            'company' => null,
            'service_interest' => 'Residential Technology Service',
            'selected_plan' => null,
            'preferred_contact' => 'Email',
            'timeline' => null,
            'details' => 'Needs help planning a home technology refresh.',
            'originating_page' => '/contact',
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
            'referrer' => null,
            'contact_consent_at' => '2026-08-30 10:00:00',
            'contact_consent_ip' => '192.0.2.20',
            'contact_consent_version' => 'contact-v1',
            'sms_consent_at' => null,
            'sms_consent_ip' => null,
            'sms_consent_version' => null,
            'ip_address' => '192.0.2.20',
            'user_agent' => 'Focused test agent',
        ];
    }
}
