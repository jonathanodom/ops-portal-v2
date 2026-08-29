<?php

namespace Tests\Feature\Projects;

use App\Models\AuditEvent;
use App\Models\Capability;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectServiceTicketCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-18 15:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authorized_user_creates_canonical_ticket_and_project_link(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization);
        $this->actingAs($user)->post(route('office.projects.store'), [
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'name' => 'Network Refresh',
            'type' => 'installation_project',
            'status' => 'planning',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $project = Project::query()->sole();
        $this->assertSame('NDT-PRJ-2026-0001', $project->project_number);

        $this->actingAs($user)->get(route('office.projects.show', $project))
            ->assertOk()->assertSee('Create Service Ticket');
        $this->actingAs($user)->get(route('office.projects.service-tickets.create', $project))
            ->assertOk()
            ->assertSee('NDT-PRJ-2026-0001 — Network Refresh')
            ->assertSee($customer->display_name)
            ->assertSee('value="'.$location->id.'" selected', false)
            ->assertDontSee('name="customer_id"', false);

        $response = $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), $this->payload($location));

        $ticket = ServiceTicket::query()->sole();
        $response->assertRedirect(route('office.service-tickets.show', $ticket));
        $response->assertSessionHas('status', "Service Ticket created from {$project->project_number} — {$project->name}.");
        $this->assertSame('NDT-ST-2026-0001', $ticket->ticket_number);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertDatabaseHas('project_service_ticket', [
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'service_ticket_id' => $ticket->id,
            'linked_by_id' => $user->id,
        ]);
        $this->assertTrue(AuditEvent::query()->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)->where('event_type', 'service_ticket.linked')->exists());
        $this->assertTrue(AuditEvent::query()->where('subject_type', $project->getMorphClass())
            ->where('subject_id', $project->id)->where('event_type', 'project.created')->exists());
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'project',
            'current_value' => 1,
        ]);
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'service_ticket',
            'current_value' => 1,
        ]);
        $this->actingAs($user)->get(route('office.projects.show', $project))
            ->assertOk()->assertSee($ticket->ticket_number)->assertSee($ticket->title);
    }

    public function test_project_customer_is_fixed_and_cross_customer_context_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization);
        [$otherCustomer, $otherLocation] = $this->customerLocation($organization, 'Other Customer');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($location), 'customer_id' => $otherCustomer->id,
        ])->assertSessionHasErrors('customer_id');
        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($otherLocation),
        ])->assertSessionHasErrors('service_location_id');
        $this->assertDatabaseCount('service_tickets', 0);
    }

    public function test_alternate_project_location_requires_confirmation_and_rolls_back_before_retry(): void
    {
        $organization = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization);
        $alternate = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'active' => true]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
        ]);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), $this->payload($alternate))
            ->assertSessionHasErrors('confirm_location_mismatch');
        $this->assertDatabaseCount('service_tickets', 0);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseMissing('document_sequences', ['organization_id' => $organization->id, 'document_type' => 'service_ticket']);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($alternate), 'confirm_location_mismatch' => '1',
        ])->assertRedirect();
        $ticket = ServiceTicket::query()->sole();
        $this->assertSame($alternate->id, $ticket->service_location_id);
        $this->assertSame('NDT-ST-2026-0001', $ticket->ticket_number);
    }

    public function test_optional_first_visit_keeps_assignment_lead_and_schedule_behavior(): void
    {
        $organization = Organization::factory()->create();
        [$user, $membership] = $this->userWithRole($organization, 'super_admin');
        [$customer, $location] = $this->customerLocation($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id]);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($location),
            'create_visit' => '1',
            'scheduled_start' => '2026-08-19T09:00',
            'scheduled_end' => '2026-08-19T10:00',
            'assignees' => [$membership->id],
        ])->assertRedirect();

        $visit = Visit::query()->sole();
        $this->assertSame('assigned', $visit->status);
        $this->assertDatabaseHas('visit_assignments', [
            'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id,
            'is_lead' => true,
        ]);
        $this->assertSame('2026-08-19 14:00:00', $visit->scheduled_start_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_initial_visit_conflicts_use_the_canonical_confirmation_and_rollback(): void
    {
        $organization = Organization::factory()->create();
        [$user, $membership] = $this->userWithRole($organization, 'super_admin');
        [$customer, $location] = $this->customerLocation($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $visitData = [
            'create_visit' => '1',
            'scheduled_start' => '2026-08-19T09:00',
            'scheduled_end' => '2026-08-19T10:00',
            'assignees' => [$membership->id],
        ];

        $this->actingAs($user)->post(route('office.service-tickets.store'), [
            ...$this->payload($location), 'customer_id' => $customer->id, ...$visitData,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($location), ...$visitData,
        ])->assertSessionHasErrors('schedule_conflict');
        $this->assertDatabaseCount('service_tickets', 1);
        $this->assertDatabaseCount('visits', 1);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $project), [
            ...$this->payload($location), ...$visitData, 'confirm_conflicts' => '1',
        ])->assertRedirect();
        $this->assertDatabaseCount('service_tickets', 2);
        $this->assertDatabaseCount('visits', 2);
    }

    public function test_both_project_admin_and_ticket_create_authority_are_required(): void
    {
        $organization = Organization::factory()->create();
        [$user, $membership] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);

        $this->deny($membership, 'dispatch.manage');
        $this->actingAs($user)->get(route('office.projects.service-tickets.create', $project))->assertForbidden();

        [$ticketCreator, $ticketMembership] = $this->userWithRole($organization, 'dispatcher');
        $this->deny($ticketMembership, 'projects.admin');
        $this->actingAs($ticketCreator)->post(route('office.projects.service-tickets.store', $project), $this->payload($location))->assertForbidden();
        $this->assertDatabaseCount('service_tickets', 0);
    }

    public function test_customerless_and_cross_organization_projects_fail_safely(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        [$user] = $this->userWithRole($organization, 'dispatcher');
        [$customer, $location] = $this->customerLocation($organization);
        $internal = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => null, 'type' => 'internal']);
        $foreign = Project::factory()->create(['organization_id' => $other->id, 'customer_id' => Customer::factory()->create(['organization_id' => $other->id])->id]);

        $this->actingAs($user)->post(route('office.projects.service-tickets.store', $internal), $this->payload($location))
            ->assertSessionHasErrors('project');
        $this->actingAs($user)->get(route('office.projects.service-tickets.create', $foreign))->assertNotFound();
        $this->assertDatabaseCount('service_tickets', 0);
    }

    private function payload(ServiceLocation $location): array
    {
        return [
            'service_location_id' => $location->id,
            'title' => 'Project service work',
            'priority' => 'normal',
            'source' => 'internal',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
        ];
    }

    private function userWithRole(Organization $organization, string $role): array
    {
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$user, $membership];
    }

    private function customerLocation(Organization $organization, string $name = 'Project Customer'): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => $name, 'status' => 'active']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'active' => true]);

        return [$customer, $location];
    }

    private function deny(OrganizationMembership $membership, string $key): void
    {
        $membership->capabilityOverrides()->attach(Capability::query()->where('key', $key)->firstOrFail(), ['effect' => 'deny']);
    }
}
