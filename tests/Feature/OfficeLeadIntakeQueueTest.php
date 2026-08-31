<?php

namespace Tests\Feature;

use App\Domain\Commercial\LeadIntakeCreator;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\CommercialLeadIntake;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfficeLeadIntakeQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-31 18:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_queue_filters_searches_and_scopes_leads_to_the_active_organization(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $other = Organization::factory()->create();
        $open = $this->lead($organization, ['first_name' => 'Avery', 'company' => 'Northwind Labs', 'service_interest' => 'Network Design']);
        $archived = $this->lead($organization, ['first_name' => 'Morgan', 'email' => 'archive@example.test', 'status' => 'archived']);
        $this->lead($organization, ['first_name' => 'Taylor', 'status' => 'spam']);
        $this->lead($other, ['first_name' => 'Foreign', 'service_interest' => 'Network Design']);

        $this->actingAs($dispatcher)->get(route('office.leads.index'))
            ->assertOk()->assertSee('Avery')->assertDontSee('Morgan')->assertDontSee('Foreign');
        $this->actingAs($dispatcher)->get(route('office.leads.index', ['filter' => 'archived']))
            ->assertOk()->assertSee('Morgan')->assertDontSee('Avery');
        $this->actingAs($dispatcher)->get(route('office.leads.index', ['filter' => 'all', 'search' => 'Network Design']))
            ->assertOk()->assertSee('Avery')->assertDontSee('Foreign');
        $this->actingAs($dispatcher)->get(route('office.leads.index', ['filter' => 'all', 'search' => 'archive@example.test']))
            ->assertOk()->assertSee('Morgan')->assertDontSee('Avery');
        $this->assertSame($open->id, CommercialLeadIntake::query()->forOrganization($organization->id)->where('status', 'received')->sole()->id);
        $this->assertSame('archived', $archived->status);
    }

    public function test_detail_shows_contact_attribution_and_separate_consent_without_technical_or_raw_evidence(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $lead = $this->lead($organization, [
            'originating_page' => '/business/networking',
            'utm_source' => 'search-source',
            'utm_campaign' => 'campaign-safe',
            'referrer' => 'https://referrer.example.test/path',
            'contact_consent_at' => now(),
            'contact_consent_version' => 'contact-v2',
            'sms_consent_at' => null,
            'sms_consent_version' => null,
            'ip_address' => '192.0.2.99',
            'user_agent' => 'Secret technical agent',
            'details' => 'Customer-safe inquiry details.',
        ]);

        $response = $this->actingAs($dispatcher)->get(route('office.leads.show', $lead))->assertOk();
        $response->assertSee('Contact consent')->assertSee('Confirmed')->assertSee('contact-v2')
            ->assertSee('SMS consent')->assertSee('Not provided')->assertSee('campaign-safe')
            ->assertSee('Customer-safe inquiry details.')
            ->assertDontSee('192.0.2.99')->assertDontSee('Secret technical agent')
            ->assertDontSee('payload_sha256')->assertDontSee('turnstile');
    }

    public function test_received_lead_converts_through_existing_converter_and_already_converted_detail_links_opportunity(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $lead = $this->lead($organization, ['service_interest' => 'Managed IT']);

        $response = $this->actingAs($dispatcher)->post(route('office.leads.convert', $lead));
        $opportunity = Opportunity::query()->sole();
        $response->assertRedirect(route('office.opportunities.show', $opportunity));
        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertSame($opportunity->id, $lead->fresh()->opportunity_id);

        $this->actingAs($dispatcher)->get(route('office.leads.show', $lead))
            ->assertOk()->assertSee('Open Opportunity')->assertDontSee('Convert to Opportunity');
        $this->actingAs($dispatcher)->post(route('office.leads.convert', $lead))
            ->assertRedirect(route('office.opportunities.show', $opportunity));
        $this->assertDatabaseCount('opportunities', 1);
    }

    public function test_ambiguous_conversion_returns_clear_error_without_mutation(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $lead = $this->lead($organization, ['email' => 'ambiguous@example.test']);
        foreach (range(1, 2) as $index) {
            $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
            Contact::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'name' => "Contact {$index}",
                'email' => 'ambiguous@example.test',
                'active' => true,
                'is_preferred' => false,
            ]);
        }

        $this->actingAs($dispatcher)->from(route('office.leads.show', $lead))
            ->post(route('office.leads.convert', $lead))
            ->assertRedirect(route('office.leads.show', $lead))->assertSessionHasErrors('lead_intake');
        $this->assertSame('received', $lead->fresh()->status);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_spam_archive_reopen_and_converted_guards_are_audited(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $spam = $this->lead($organization);
        $archive = $this->lead($organization, ['email' => 'archive@example.test']);

        $this->actingAs($dispatcher)->post(route('office.leads.spam', $spam))->assertRedirect();
        $this->assertSame('spam', $spam->fresh()->status);
        $this->actingAs($dispatcher)->post(route('office.leads.reopen', $spam))->assertRedirect();
        $this->assertSame('received', $spam->fresh()->status);
        $this->actingAs($dispatcher)->post(route('office.leads.archive', $archive))->assertRedirect();
        $this->assertSame('archived', $archive->fresh()->status);
        $this->actingAs($dispatcher)->post(route('office.leads.reopen', $archive))->assertRedirect();
        $this->assertSame('received', $archive->fresh()->status);

        $converted = $this->lead($organization, ['email' => 'converted@example.test', 'status' => 'converted']);
        $this->actingAs($dispatcher)->post(route('office.leads.archive', $converted))->assertSessionHasErrors('lead_intake');
        $this->assertSame('converted', $converted->fresh()->status);

        $events = AuditEvent::query()->where('subject_type', $spam->getMorphClass())->pluck('event_type')->all();
        $this->assertContains('commercial_lead_intake.marked_spam', $events);
        $this->assertContains('commercial_lead_intake.reopened', $events);
        $this->assertDatabaseHas('audit_events', [
            'subject_id' => $archive->id,
            'event_type' => 'commercial_lead_intake.archived',
        ]);
        $this->assertStringNotContainsString($spam->email, AuditEvent::query()->where('subject_id', $spam->id)->pluck('metadata')->toJson());
    }

    public function test_navigation_badge_counts_only_received_leads_in_the_active_organization(): void
    {
        [$organization, $dispatcher] = $this->organizationMember('dispatcher');
        $other = Organization::factory()->create();
        $this->lead($organization);
        $this->lead($organization, ['email' => 'second@example.test']);
        $this->lead($organization, ['email' => 'spam@example.test', 'status' => 'spam']);
        $this->lead($other);

        $this->actingAs($dispatcher)->get(route('office.leads.index'))
            ->assertOk()->assertSee('data-office-nav-key="leads"', false)->assertSee('Leads (2)');
    }

    public function test_view_manage_capabilities_inactive_membership_and_cross_organization_access_are_enforced(): void
    {
        [$organization, $dispatcher, $dispatcherMembership] = $this->organizationMember('dispatcher');
        [$other, $otherDispatcher] = $this->organizationMember('dispatcher');
        [, $reviewer, $reviewerMembership] = $this->organizationMember('reviewer', $organization);
        $lead = $this->lead($organization);

        $reviewerMembership->capabilityOverrides()->attach(
            Capability::query()->where('key', 'opportunities.view')->sole(),
            ['effect' => 'grant'],
        );
        $this->actingAs($reviewer)->get(route('office.leads.show', $lead))->assertOk()->assertDontSee('Convert to Opportunity');
        $this->actingAs($reviewer)->post(route('office.leads.archive', $lead))->assertForbidden();
        $this->actingAs($otherDispatcher)->get(route('office.leads.show', $lead))->assertNotFound();
        $this->actingAs($otherDispatcher)->post(route('office.leads.convert', $lead))->assertNotFound();

        $dispatcherMembership->update(['status' => 'inactive']);
        $this->actingAs($dispatcher)->get(route('office.leads.index'))->assertForbidden();
        $this->assertSame($other->id, $otherDispatcher->memberships()->sole()->organization_id);
    }

    private function organizationMember(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$organization, $user, $membership];
    }

    private function lead(Organization $organization, array $overrides = []): CommercialLeadIntake
    {
        return app(LeadIntakeCreator::class)->create($organization, [
            ...[
                'source' => 'website',
                'first_name' => 'Jordan',
                'last_name' => 'Rivera',
                'phone' => '555-010-4400',
                'email' => 'jordan@example.test',
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
                'ip_address' => '192.0.2.40',
                'user_agent' => 'Focused test agent',
            ],
            ...$overrides,
        ]);
    }
}
