<?php

namespace Tests\Feature;

use App\Jobs\DeleteRemovedOpportunityAttachment;
use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\CommercialUserPreference;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\OpportunityAttachment;
use App\Models\OpportunityStage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CommercialOperationsPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-27 16:00:00 UTC');
        config(['commercial.attachment_disk' => 'commercial-test']);
        Storage::fake('commercial-test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatcher_creates_scoped_numbered_opportunity_with_canonical_context(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$dispatcher] = $this->member($organization, 'dispatcher');
        [$customer, $location, $contact] = $this->customerContext($organization);

        $response = $this->actingAs($dispatcher)->get(route('office.opportunities.create'))->assertOk();
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole();
        $response->assertSee('New opportunity')->assertSee($customer->display_name);
        $this->actingAs($dispatcher)->post(route('office.opportunities.store'), [
            'customer_id' => $customer->id, 'service_location_id' => $location->id, 'primary_contact_id' => $contact->id,
            'stage_id' => $stage->id, 'title' => 'Network modernization', 'priority' => 'high',
            'estimated_value_cents' => 1250000, 'estimated_close_on' => '2026-10-01', 'next_action' => 'Schedule discovery.',
        ])->assertRedirect();

        $opportunity = Opportunity::query()->sole();
        $this->assertSame('OPP-2026-0001', $opportunity->opportunity_number);
        $this->assertSame($customer->id, $opportunity->customer_id);
        $this->assertSame($dispatcher->id, $opportunity->owner_user_id);
        $this->assertDatabaseHas('document_sequences', ['organization_id' => $organization->id, 'document_type' => 'opportunity', 'year' => 2026, 'current_value' => 1]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'opportunity.created', 'subject_id' => $opportunity->id]);
    }

    public function test_customer_is_required_and_site_contact_owner_and_stage_are_tenant_scoped(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [$dispatcher] = $this->member($organization, 'dispatcher');
        [$foreignCustomer, $foreignLocation, $foreignContact] = $this->customerContext($other);
        $foreignStage = OpportunityStage::query()->create(['organization_id' => $other->id, 'name' => 'New', 'semantic_kind' => 'new', 'default_probability_bps' => 1000]);
        $payload = ['customer_id' => $foreignCustomer->id, 'service_location_id' => $foreignLocation->id, 'primary_contact_id' => $foreignContact->id, 'stage_id' => $foreignStage->id, 'title' => 'Forged', 'priority' => 'normal', 'estimated_value_cents' => 0];
        $this->actingAs($dispatcher)->post(route('office.opportunities.store'), $payload)->assertNotFound();
        $this->actingAs($dispatcher)->post(route('office.opportunities.store'), [...$payload, 'customer_id' => null])->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_protected_stage_override_lost_reopen_and_won_final_rules_are_enforced(): void
    {
        $organization = Organization::factory()->create();
        [$dispatcher] = $this->member($organization, 'dispatcher');
        [$admin] = $this->member($organization, 'super_admin');
        [$customer] = $this->customerContext($organization);
        $this->actingAs($dispatcher)->get(route('office.opportunities.index'))->assertOk();
        $stages = OpportunityStage::query()->where('organization_id', $organization->id)->get()->keyBy('semantic_kind');
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'owner_user_id' => $dispatcher->id, 'stage_id' => $stages['new']->id, 'title' => 'Lifecycle', 'priority' => 'normal', 'estimated_value_cents' => 10000]);

        $this->actingAs($dispatcher)->put(route('office.opportunities.update', $opportunity), $this->payload($opportunity, $stages['presented']->id))->assertSessionHasErrors('stage_id');
        $this->actingAs($admin)->put(route('office.opportunities.update', $opportunity), [...$this->payload($opportunity, $stages['presented']->id), 'confirm_admin_override' => '1'])->assertRedirect();
        $this->actingAs($dispatcher)->put(route('office.opportunities.update', $opportunity), [...$this->payload($opportunity, $stages['lost']->id), 'lost_reason' => 'Timing', 'lost_note' => 'Private details'])->assertRedirect();
        $this->assertNotNull($opportunity->fresh()->lost_at);
        $this->actingAs($dispatcher)->put(route('office.opportunities.update', $opportunity), $this->payload($opportunity, $stages['qualifying']->id))->assertRedirect();
        $this->assertNull($opportunity->fresh()->lost_at);
        $this->assertNull($opportunity->fresh()->lost_note);
        $this->actingAs($admin)->put(route('office.opportunities.update', $opportunity), [...$this->payload($opportunity, $stages['won']->id), 'confirm_admin_override' => '1'])->assertRedirect();
        $this->actingAs($admin)->put(route('office.opportunities.update', $opportunity), $this->payload($opportunity, $stages['new']->id))->assertSessionHasErrors('stage_id');
        $metadata = AuditEvent::query()->where('subject_id', $opportunity->id)->pluck('metadata')->toJson();
        $this->assertStringNotContainsString('Private details', $metadata);
        $this->assertStringContainsString('protected_stage', $metadata);
    }

    public function test_tasks_activity_and_safe_audit_form_one_bounded_detail_timeline(): void
    {
        [$organization, $admin] = $this->organizationMember('super_admin');
        [$customer] = $this->customerContext($organization);
        $this->actingAs($admin)->get(route('office.opportunities.index'))->assertOk();
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'qualifying')->sole();
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'stage_id' => $stage->id, 'owner_user_id' => $admin->id, 'title' => 'Timeline', 'priority' => 'normal', 'estimated_value_cents' => 250000]);
        $this->actingAs($admin)->post(route('office.opportunities.tasks.store', $opportunity), ['title' => 'Call customer', 'status' => 'open', 'assigned_to_user_id' => $admin->id, 'due_on' => '2026-08-28', 'description' => 'Sensitive task detail'])->assertRedirect();
        $this->actingAs($admin)->post(route('office.opportunities.activities.store', $opportunity), ['type' => 'call', 'body' => 'Sensitive call summary'])->assertRedirect();
        $response = $this->actingAs($admin)->get(route('office.opportunities.show', $opportunity))->assertOk();
        $response->assertSee('Call customer')->assertSee('Sensitive call summary')->assertSee('Quote / proposal')->assertSee('Not started');
        $metadata = AuditEvent::query()->where('subject_id', $opportunity->id)->pluck('metadata')->toJson();
        $this->assertStringNotContainsString('Sensitive task detail', $metadata);
        $this->assertStringNotContainsString('Sensitive call summary', $metadata);
    }

    public function test_private_files_are_opaque_scoped_authorized_and_soft_removed(): void
    {
        Queue::fake();
        [$organization, $admin] = $this->organizationMember('super_admin');
        [$customer] = $this->customerContext($organization);
        $this->actingAs($admin)->get(route('office.opportunities.index'))->assertOk();
        $stage = OpportunityStage::query()->where('organization_id', $organization->id)->where('semantic_kind', 'new')->sole();
        $opportunity = Opportunity::query()->create(['organization_id' => $organization->id, 'opportunity_number' => 'OPP-2026-0001', 'customer_id' => $customer->id, 'stage_id' => $stage->id, 'title' => 'Files', 'priority' => 'normal']);
        $this->actingAs($admin)->post(route('office.opportunities.attachments.store', $opportunity), ['file' => UploadedFile::fake()->create('scope.pdf', 5, 'application/pdf'), 'caption' => 'Private caption'])->assertRedirect();
        $attachment = OpportunityAttachment::query()->sole();
        $this->assertMatchesRegularExpression('#commercial/opportunities/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf#', $attachment->storage_key);
        $this->assertStringNotContainsString('scope', $attachment->storage_key);
        Storage::disk('commercial-test')->assertExists($attachment->storage_key);
        $this->actingAs($admin)->get(route('office.opportunities.attachments.show', [$opportunity, $attachment]))->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->actingAs($admin)->delete(route('office.opportunities.attachments.destroy', [$opportunity, $attachment]))->assertRedirect();
        $this->assertSame('removed', $attachment->fresh()->state);
        Queue::assertPushed(DeleteRemovedOpportunityAttachment::class);
        $this->assertStringNotContainsString('Private caption', AuditEvent::query()->where('subject_id', $opportunity->id)->pluck('metadata')->toJson());
    }

    public function test_view_preference_role_defaults_explicit_denial_and_inactive_membership_are_authoritative(): void
    {
        $organization = Organization::factory()->create();
        [$dispatcher, $dispatcherMembership] = $this->member($organization, 'dispatcher');
        [$reviewer] = $this->member($organization, 'reviewer');
        $this->actingAs($dispatcher)->get(route('office.opportunities.index'))->assertOk()->assertSee('Kanban');
        $this->actingAs($dispatcher)->get(route('office.opportunities.index', ['view' => 'list']))->assertOk();
        $this->assertSame('list', CommercialUserPreference::query()->where('organization_id', $organization->id)->where('user_id', $dispatcher->id)->value('opportunity_view'));
        $this->actingAs($dispatcher)->get(route('office.opportunities.index'))->assertOk()->assertSee('data-office-width="workspace"', false);
        $this->actingAs($reviewer)->get(route('office.opportunities.index'))->assertForbidden();
        $dispatcherMembership->capabilityOverrides()->attach(Capability::query()->where('key', 'opportunities.view')->sole(), ['effect' => 'deny']);
        $this->actingAs($dispatcher)->get(route('office.opportunities.index'))->assertForbidden();
        $dispatcherMembership->update(['status' => 'inactive']);
        $this->actingAs($dispatcher)->get(route('office.home'))->assertForbidden();
    }

    public function test_admin_configures_pipeline_while_dispatcher_cannot_access_settings(): void
    {
        $organization = Organization::factory()->create();
        [$admin] = $this->member($organization, 'super_admin');
        [$dispatcher] = $this->member($organization, 'dispatcher');
        $this->actingAs($admin)->get(route('office.settings.commercial.edit'))->assertOk()->assertSee('Pipeline stages');
        $stages = OpportunityStage::query()->where('organization_id', $organization->id)->get();
        $payload = ['default_proposal_expiration_days' => 45, 'gross_margin_floor_bps' => 2500, 'discount_approval_ceiling_bps' => 1200, 'first_reminder_days' => 10, 'second_reminder_days' => 3, 'notification_policy' => 'staff_only', 'approve_manual_price_overrides' => '1', 'approve_below_cost_lines' => '1', 'approve_terms_overrides' => '1', 'stages' => []];
        foreach ($stages as $stage) {
            $payload['stages'][$stage->id] = ['name' => $stage->semantic_kind === 'qualifying' ? 'Discovery' : $stage->name, 'default_probability_bps' => $stage->default_probability_bps, 'color' => $stage->color, 'sort_order' => $stage->sort_order];
        }
        $this->actingAs($admin)->put(route('office.settings.commercial.update'), $payload)->assertRedirect();
        $this->assertDatabaseHas('opportunity_stages', ['organization_id' => $organization->id, 'semantic_kind' => 'qualifying', 'name' => 'Discovery']);
        $this->actingAs($dispatcher)->get(route('office.settings.commercial.edit'))->assertForbidden();
    }

    private function payload(Opportunity $opportunity, int $stageId): array
    {
        return ['customer_id' => $opportunity->customer_id, 'service_location_id' => $opportunity->service_location_id, 'primary_contact_id' => $opportunity->primary_contact_id, 'owner_user_id' => $opportunity->owner_user_id, 'stage_id' => $stageId, 'title' => $opportunity->title, 'priority' => $opportunity->priority, 'estimated_value_cents' => $opportunity->estimated_value_cents];
    }

    private function organizationMember(string $role): array
    {
        $organization = Organization::factory()->create();
        [$user] = $this->member($organization, $role);

        return [$organization, $user];
    }

    private function member(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->sole());

        return [$user, $membership];
    }

    private function customerContext(Organization $organization): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'status' => 'active']);
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'active' => true]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'active' => true]);

        return [$customer, $location, $contact];
    }
}
