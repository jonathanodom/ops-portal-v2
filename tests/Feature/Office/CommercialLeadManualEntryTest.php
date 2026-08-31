<?php

namespace Tests\Feature\Office;

use App\Models\AuditEvent;
use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CommercialLeadManualEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-31 19:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_user_can_open_and_create_a_manual_lead_through_the_canonical_creator(): void
    {
        [$organization, $admin] = $this->organizationMember('super_admin');
        $foreign = Organization::factory()->create();

        $this->actingAs($admin)->get(route('office.leads.create'))
            ->assertOk()
            ->assertSee('New Lead')
            ->assertSee('General contact permission confirmed')
            ->assertSee('SMS consent confirmed separately')
            ->assertDontSee('turnstileToken')
            ->assertDontSee('website');

        $response = $this->actingAs($admin)->post(route('office.leads.store'), [
            ...$this->validPayload(),
            'organization_id' => $foreign->id,
            'source' => 'website',
            'status' => 'spam',
        ]);

        $response->assertSessionHasNoErrors();
        $lead = CommercialLeadIntake::query()->sole();
        $response->assertRedirect(route('office.leads.show', $lead))->assertSessionHas('status', 'Lead created.');
        $this->assertSame($organization->id, $lead->organization_id);
        $this->assertSame('manual', $lead->source);
        $this->assertSame('received', $lead->status);
        $this->assertSame('Taylor', $lead->first_name);
        $this->assertSame('Technology Support', $lead->service_interest);
        $this->assertNull($lead->contact_consent_at);
        $this->assertNull($lead->sms_consent_at);
        $this->assertNull($lead->ip_address);
        $this->assertNull($lead->user_agent);
        $this->assertFalse($lead->payload['contact_consent']);
        $this->assertFalse($lead->payload['sms_consent']);

        $this->actingAs($admin)->get(route('office.leads.index'))
            ->assertOk()->assertSee('Taylor Rivera');

        $event = AuditEvent::query()->where('event_type', 'commercial_lead_intake.created_manual')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame(['lead_intake_id' => $lead->id, 'source' => 'manual'], $event->metadata);
        $this->assertStringNotContainsString($lead->email, json_encode($event->metadata));
    }

    public function test_manual_contact_and_sms_consent_are_recorded_independently_without_customer_ip_attribution(): void
    {
        [, $admin] = $this->organizationMember('super_admin');

        $this->actingAs($admin)->post(route('office.leads.store'), [
            ...$this->validPayload(),
            'preferred_contact' => 'Text',
            'contact_consent' => '1',
        ])->assertRedirect();

        $contactOnly = CommercialLeadIntake::query()->sole();
        $this->assertNotNull($contactOnly->contact_consent_at);
        $this->assertSame('manual-v1', $contactOnly->contact_consent_version);
        $this->assertNull($contactOnly->contact_consent_ip);
        $this->assertNull($contactOnly->sms_consent_at);
        $this->assertNull($contactOnly->sms_consent_version);
        $this->assertFalse($contactOnly->payload['sms_consent']);

        $this->actingAs($admin)->post(route('office.leads.store'), [
            ...$this->validPayload(),
            'email' => 'sms@example.test',
            'sms_consent' => '1',
        ])->assertRedirect();

        $smsOnly = CommercialLeadIntake::query()->latest('id')->firstOrFail();
        $this->assertNull($smsOnly->contact_consent_at);
        $this->assertNotNull($smsOnly->sms_consent_at);
        $this->assertSame('manual-v1', $smsOnly->sms_consent_version);
        $this->assertNull($smsOnly->sms_consent_ip);
    }

    public function test_manual_form_validation_preserves_input_and_does_not_create_a_lead(): void
    {
        [, $admin] = $this->organizationMember('super_admin');

        $this->actingAs($admin)->from(route('office.leads.create'))->post(route('office.leads.store'), [
            ...$this->validPayload(),
            'customer_type' => 'Business',
            'company' => '',
            'details' => 'short',
        ])->assertRedirect(route('office.leads.create'))
            ->assertSessionHasErrors(['company', 'details'])
            ->assertSessionHasInput('first_name', 'Taylor');

        $this->assertDatabaseCount('commercial_lead_intakes', 0);
    }

    public function test_users_without_opportunity_management_cannot_open_or_submit_manual_leads(): void
    {
        [, $reviewer] = $this->organizationMember('reviewer');

        $this->actingAs($reviewer)->get(route('office.leads.create'))->assertForbidden();
        $this->actingAs($reviewer)->post(route('office.leads.store'), $this->validPayload())->assertForbidden();
        $this->assertDatabaseCount('commercial_lead_intakes', 0);
    }

    private function organizationMember(string $role): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$organization, $user, $membership];
    }

    private function validPayload(): array
    {
        return [
            'first_name' => 'Taylor',
            'last_name' => 'Rivera',
            'phone' => '940-555-1212',
            'email' => 'taylor@example.test',
            'customer_type' => 'Individual',
            'company' => null,
            'zip' => '76450',
            'service_interest' => 'Technology Support',
            'selected_plan' => 'Care Plan',
            'preferred_contact' => 'Email',
            'timeline' => 'Within 30 days',
            'details' => 'Customer called to discuss a residential technology upgrade.',
        ];
    }
}
