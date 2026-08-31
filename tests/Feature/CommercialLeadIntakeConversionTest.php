<?php

namespace Tests\Feature;

use App\Domain\Commercial\CommercialDefaults;
use App\Domain\Commercial\LeadIntakeConverter;
use App\Domain\Commercial\LeadIntakeCreator;
use App\Models\AuditEvent;
use App\Models\CommercialLeadIntake;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\Opportunity;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class CommercialLeadIntakeConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-31 15:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_contact_email_match_converts_with_existing_opportunity_conventions_and_is_idempotent(): void
    {
        [$organization, $actor] = $this->organizationActor();
        $customer = $this->customer($organization, ['email' => 'customer@example.test']);
        $contact = $this->contact($organization, $customer, [
            'email' => '  LEAD@EXAMPLE.TEST ',
            'phone' => '555-010-1111',
            'phone_normalized' => '5550101111',
        ]);
        $intake = $this->intake($organization, [
            'email' => 'lead@example.test',
            'phone' => '555-010-9999',
            'service_interest' => 'Managed Technology',
        ]);
        $evidence = $intake->only(['payload', 'payload_sha256', 'contact_consent_at', 'sms_consent_at']);

        $opportunity = app(LeadIntakeConverter::class)->convert($organization, $intake, $actor);
        $convertedAt = $intake->fresh()->converted_at;
        $second = app(LeadIntakeConverter::class)->convert($organization, $intake, $actor);

        $this->assertTrue($opportunity->is($second));
        $this->assertSame($customer->id, $opportunity->customer_id);
        $this->assertSame($contact->id, $opportunity->primary_contact_id);
        $this->assertSame('website', $opportunity->lead_source);
        $this->assertSame('Managed Technology — '.$customer->display_name, $opportunity->title);
        $this->assertSame('new', $opportunity->stage->semantic_kind);
        $this->assertSame($actor->id, $opportunity->owner_user_id);
        $this->assertSame('converted', $intake->fresh()->status);
        $this->assertSame($actor->id, $intake->fresh()->converted_by_id);
        $this->assertTrue($convertedAt->equalTo($intake->fresh()->converted_at));
        $this->assertEquals($evidence['payload'], $intake->fresh()->payload);
        $this->assertSame($evidence['payload_sha256'], $intake->fresh()->payload_sha256);
        $this->assertTrue($evidence['contact_consent_at']->equalTo($intake->fresh()->contact_consent_at));
        $this->assertNull($intake->fresh()->sms_consent_at);
        $this->assertDatabaseCount('opportunities', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('contacts', 1);

        $audit = AuditEvent::query()->where('event_type', 'commercial_lead_intake.converted')->sole();
        $this->assertSame('contact_email', $audit->metadata['match_strategy']);
        $this->assertStringNotContainsString('lead@example.test', json_encode($audit->metadata));
    }

    public function test_contact_phone_match_precedes_customer_matches(): void
    {
        [$organization, $actor] = $this->organizationActor();
        $contactCustomer = $this->customer($organization, ['email' => 'different@example.test']);
        $contact = $this->contact($organization, $contactCustomer, [
            'email' => 'different-contact@example.test',
            'phone' => '(555) 010-2222',
            'phone_normalized' => '5550102222',
        ]);
        $this->customer($organization, ['email' => 'lead@example.test']);

        $opportunity = app(LeadIntakeConverter::class)->convert(
            $organization,
            $this->intake($organization, ['email' => 'lead@example.test', 'phone' => '555-010-2222']),
            $actor,
        );

        $this->assertSame($contactCustomer->id, $opportunity->customer_id);
        $this->assertSame($contact->id, $opportunity->primary_contact_id);
    }

    public function test_customer_email_and_phone_matches_are_supported_without_fabricating_contacts(): void
    {
        foreach (['email', 'phone'] as $matchBy) {
            [$organization, $actor] = $this->organizationActor();
            $customer = $this->customer($organization, [
                'email' => $matchBy === 'email' ? 'customer-match@example.test' : 'other@example.test',
                'phone' => '555-010-3333',
                'phone_normalized' => '5550103333',
            ]);
            $intake = $this->intake($organization, [
                'email' => $matchBy === 'email' ? 'CUSTOMER-MATCH@example.test' : 'lead-'.$organization->id.'@example.test',
                'phone' => $matchBy === 'phone' ? '(555) 010-3333' : '555-010-9999',
            ]);

            $opportunity = app(LeadIntakeConverter::class)->convert($organization, $intake, $actor);

            $this->assertSame($customer->id, $opportunity->customer_id);
            $this->assertNull($opportunity->primary_contact_id);
            $this->assertSame(0, Contact::query()->where('organization_id', $organization->id)->count());
        }
    }

    public function test_unmatched_individual_is_organization_scoped_and_never_name_or_company_matched(): void
    {
        [$organization, $actor] = $this->organizationActor();
        $other = Organization::factory()->create();
        $this->customer($organization, ['display_name' => 'Jordan Rivera', 'email' => 'old@example.test']);
        $this->customer($other, ['email' => 'lead@example.test', 'phone_normalized' => '5550104400']);

        $opportunity = app(LeadIntakeConverter::class)->convert(
            $organization,
            $this->intake($organization, ['phone' => '555-010-4400']),
            $actor,
        );
        $created = $opportunity->customer;

        $this->assertSame($organization->id, $created->organization_id);
        $this->assertSame('individual', $created->type);
        $this->assertSame('Jordan Rivera', $created->display_name);
        $this->assertSame('5550104400', $created->phone_normalized);
        $this->assertNull($opportunity->primary_contact_id);
        $this->assertDatabaseCount('service_locations', 0);
        $this->assertSame(2, Customer::query()->where('organization_id', $organization->id)->count());
    }

    public function test_unmatched_business_creates_customer_and_preferred_contact_without_location(): void
    {
        [$organization, $actor] = $this->organizationActor();
        $opportunity = app(LeadIntakeConverter::class)->convert(
            $organization,
            $this->intake($organization, ['customer_type' => 'Business', 'company' => 'Rivera Systems']),
            $actor,
        );

        $customer = $opportunity->customer;
        $contact = $opportunity->primaryContact;
        $this->assertSame('business', $customer->type);
        $this->assertSame('Rivera Systems', $customer->display_name);
        $this->assertSame('Rivera Systems', $customer->legal_name);
        $this->assertSame('Jordan Rivera', $contact->name);
        $this->assertTrue($contact->is_preferred);
        $this->assertTrue($contact->active);
        $this->assertDatabaseCount('service_locations', 0);
    }

    public function test_ambiguous_email_or_phone_rejects_without_creating_any_records(): void
    {
        foreach (['email', 'phone'] as $field) {
            [$organization, $actor] = $this->organizationActor();
            foreach (range(1, 2) as $index) {
                $customer = $this->customer($organization, ['email' => "customer{$index}@example.test"]);
                $this->contact($organization, $customer, [
                    'email' => $field === 'email' ? 'lead@example.test' : "contact{$index}@example.test",
                    'phone_normalized' => $field === 'phone' ? '5550104400' : '555010'.(5000 + $index),
                ]);
            }

            try {
                app(LeadIntakeConverter::class)->convert($organization, $this->intake($organization), $actor);
                $this->fail('Ambiguous conversion should fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lead_intake', $exception->errors());
            }

            $this->assertSame(0, Opportunity::query()->where('organization_id', $organization->id)->count());
        }
    }

    public function test_wrong_organization_is_rejected(): void
    {
        [$organization, $actor] = $this->organizationActor();
        $other = Organization::factory()->create();
        $intake = $this->intake($organization);

        $this->expectException(ModelNotFoundException::class);
        app(LeadIntakeConverter::class)->convert($other, $intake, $actor);
    }

    public function test_actor_must_be_an_active_member_of_the_intake_organization(): void
    {
        [$organization] = $this->organizationActor();
        $outsider = User::factory()->create();

        $this->expectException(NotFoundHttpException::class);
        app(LeadIntakeConverter::class)->convert($organization, $this->intake($organization), $outsider);
    }

    public function test_archived_spam_and_failed_statuses_are_rejected(): void
    {
        foreach (['archived', 'spam', 'failed'] as $status) {
            [$organization, $actor] = $this->organizationActor();
            $intake = $this->intake($organization);
            $intake->update(['status' => $status]);

            try {
                app(LeadIntakeConverter::class)->convert($organization, $intake, $actor);
                $this->fail("{$status} conversion should fail.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lead_intake', $exception->errors());
            }
        }
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_opportunity_failure_rolls_back_new_identity_and_intake_conversion(): void
    {
        [$organization, $actor] = $this->organizationActor();
        app(CommercialDefaults::class)->ensure($organization);
        $existingCustomer = $this->customer($organization, ['email' => 'existing@example.test']);
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole();
        Opportunity::query()->create([
            'organization_id' => $organization->id,
            'opportunity_number' => 'OPP-2026-0001',
            'customer_id' => $existingCustomer->id,
            'stage_id' => $stage->id,
            'title' => 'Existing',
            'priority' => 'normal',
        ]);
        DocumentSequence::query()->create([
            'organization_id' => $organization->id,
            'document_type' => 'opportunity',
            'year' => 2026,
            'current_value' => 0,
        ]);
        $intake = $this->intake($organization, ['email' => 'new@example.test', 'phone' => '555-010-7777']);

        try {
            app(LeadIntakeConverter::class)->convert($organization, $intake, $actor);
            $this->fail('Duplicate Opportunity number should fail.');
        } catch (QueryException) {
            // Expected unique-number failure exercises the complete transaction rollback.
        }

        $this->assertSame('received', $intake->fresh()->status);
        $this->assertNull($intake->fresh()->opportunity_id);
        $this->assertSame(1, Customer::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, Opportunity::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, DocumentSequence::query()->where('organization_id', $organization->id)->value('current_value'));
    }

    private function organizationActor(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $actor = User::factory()->create();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'status' => 'active',
        ]);

        return [$organization, $actor];
    }

    private function intake(Organization $organization, array $overrides = []): CommercialLeadIntake
    {
        return app(LeadIntakeCreator::class)->create($organization, [
            ...[
                'source' => 'website',
                'first_name' => 'Jordan',
                'last_name' => 'Rivera',
                'phone' => '555-010-4400',
                'email' => 'lead@example.test',
                'customer_type' => 'Individual',
                'zip' => '75001',
                'company' => null,
                'service_interest' => 'Residential Technology Service',
                'selected_plan' => 'Care Plan',
                'preferred_contact' => 'Email',
                'timeline' => 'Within 30 days',
                'details' => 'Needs help planning a technology upgrade.',
                'contact_consent_at' => now(),
                'contact_consent_ip' => '192.0.2.40',
                'contact_consent_version' => 'contact-v1',
                'sms_consent_at' => null,
                'sms_consent_ip' => null,
            ],
            ...$overrides,
        ]);
    }

    private function customer(Organization $organization, array $overrides = []): Customer
    {
        return Customer::factory()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            ...$overrides,
        ]);
    }

    private function contact(Organization $organization, Customer $customer, array $overrides = []): Contact
    {
        return Contact::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'name' => 'Jordan Rivera',
            'email' => 'contact@example.test',
            'phone' => '555-010-4400',
            'phone_normalized' => '5550104400',
            'is_preferred' => false,
            'active' => true,
            ...$overrides,
        ]);
    }
}
