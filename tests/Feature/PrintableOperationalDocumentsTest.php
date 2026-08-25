<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Closeout;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectMilestone;
use App\Models\ProjectNote;
use App\Models\ProjectTask;
use App\Models\ProjectWorkstream;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrintableOperationalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_work_order_renders_canonical_operational_data_with_private_headers_and_no_mutation(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [$ticket, $visit, $closeout] = $this->ticketScenario($organization, $admin, $membership);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $ticket->customer_id, 'service_location_id' => $ticket->service_location_id, 'project_number' => 'NDT-PRJ-2026-0042', 'name' => 'Network Refresh', 'status' => 'active']);
        $project->serviceTickets()->attach($ticket->id, ['organization_id' => $organization->id, 'linked_by_id' => $admin->id, 'linked_at' => now()]);
        ServiceTicketFile::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'uploaded_by_id' => $admin->id, 'storage_disk' => 'local', 'storage_key' => 'secret/ticket-file-key.pdf', 'original_name' => 'rack-plan.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 2048, 'caption' => 'Rack reference']);
        VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'proposed_by_id' => $admin->id, 'description' => 'Cat6 patch cable', 'quantity' => 2, 'unit' => 'each', 'billing_treatment' => 'billable']);
        VisitMedia::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'uploader_id' => $admin->id, 'storage_disk' => 'local', 'storage_key' => 'secret/photo-key.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 512, 'category' => 'after', 'state' => 'stored']);
        AuditEvent::query()->create(['organization_id' => $organization->id, 'actor_id' => $admin->id, 'event_type' => 'audit.only.secret', 'subject_type' => $ticket->getMorphClass(), 'subject_id' => $ticket->id, 'metadata' => ['secret' => 'AUDIT-ONLY-SECRET'], 'occurred_at' => now()]);

        $before = [$ticket->fresh()->updated_at->toISOString(), $visit->fresh()->updated_at->toISOString(), $project->fresh()->updated_at->toISOString()];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('office.service-tickets.print', $ticket));
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff')->assertHeader('x-robots-tag', 'noindex, nofollow')
            ->assertSee('TECHNICIAN WORK ORDER')->assertSee($ticket->ticket_number)->assertSee('Acme Dental')->assertSee('Main Office')->assertSee('Jordan Customer')->assertSee('Replace network switch')
            ->assertSee($visit->displayLabel())->assertSee('Lead: '.$admin->name)->assertSee('Cat6 patch cable')->assertSee('Hard-Copy Customer Acknowledgment')->assertSee('Field Notes')
            ->assertDontSee('NDT-PRJ-2026-0042')->assertDontSee('Connectivity restored')->assertDontSee('rack-plan.pdf')
            ->assertDontSee('secret/ticket-file-key.pdf')->assertDontSee('secret/photo-key.jpg')->assertDontSee('AUDIT-ONLY-SECRET')->assertDontSee('Invoice balance')->assertDontSee('Office navigation');
        $this->assertLessThanOrEqual(28, $queryCount, "Work Order used {$queryCount} queries");
        $this->assertSame($before, [$ticket->fresh()->updated_at->toISOString(), $visit->fresh()->updated_at->toISOString(), $project->fresh()->updated_at->toISOString()]);
    }

    public function test_work_order_honors_policy_tenant_scope_and_closeout_projection(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [, $billing] = $this->member('billing', $organization);
        [$otherOrganization, $otherAdmin] = $this->member('super_admin');
        [, $technician] = $this->member('technician', $organization);
        [$ticket] = $this->ticketScenario($organization, $admin, $membership);

        $this->actingAs($billing)->get(route('office.service-tickets.print', $ticket))->assertOk()->assertSee('TECHNICIAN WORK ORDER')->assertDontSee('Connectivity restored');
        $this->actingAs($technician)->get(route('office.service-tickets.print', $ticket))->assertForbidden();
        $this->actingAs($otherAdmin)->get(route('office.service-tickets.print', $ticket))->assertNotFound();
        $this->assertNotSame($organization->id, $otherOrganization->id);
    }

    public function test_project_workbook_renders_grouped_operational_content_and_attachment_metadata_only(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [$ticket] = $this->ticketScenario($organization, $admin, $membership);
        $contact = $ticket->contact;
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => $ticket->customer_id, 'service_location_id' => $ticket->service_location_id, 'primary_contact_id' => $contact->id, 'project_number' => 'NDT-PRJ-2026-0088', 'name' => 'Office Modernization', 'type' => 'installation_project', 'status' => 'active', 'summary' => 'Modernize the office systems.', 'objective' => 'Deliver a stable handoff.', 'owner_user_id' => $admin->id]);
        $workstream = ProjectWorkstream::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Network', 'description' => 'Core switching scope', 'status' => 'in_progress', 'owner_user_id' => $admin->id]);
        ProjectTask::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'workstream_id' => $workstream->id, 'title' => 'Configure switch', 'description' => 'Apply approved VLAN design.', 'status' => 'blocked', 'priority' => 'high', 'assigned_to_user_id' => $admin->id, 'due_on' => now()->subDay()->toDateString(), 'blocked_reason' => 'Awaiting ISP handoff']);
        ProjectTask::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'title' => 'Prepare labels', 'status' => 'planned', 'priority' => 'normal']);
        ProjectMilestone::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Cutover', 'description' => 'Production cutover', 'status' => 'planned', 'target_on' => now()->addWeek()->toDateString()]);
        ProjectNote::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'author_id' => $admin->id, 'type' => 'decision', 'body' => 'Use the approved rack elevation.']);
        ProjectAttachment::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id, 'uploaded_by_id' => $admin->id, 'category' => 'design_document', 'state' => 'stored', 'storage_disk' => 'local', 'storage_key' => 'secret/project-object-key.pdf', 'original_name' => 'rack-elevation.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 4096, 'caption' => 'Approved elevation']);
        $project->serviceTickets()->attach($ticket->id, ['organization_id' => $organization->id, 'linked_by_id' => $admin->id, 'linked_at' => now()]);
        AuditEvent::query()->create(['organization_id' => $organization->id, 'actor_id' => $admin->id, 'event_type' => 'project.audit.secret', 'subject_type' => $project->getMorphClass(), 'subject_id' => $project->id, 'metadata' => ['secret' => 'PROJECT-AUDIT-SECRET'], 'occurred_at' => now()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('office.projects.workbook.print', $project));
        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff')
            ->assertSee('PROJECT WORKBOOK')->assertSee('Office Modernization')->assertSee('Acme Dental')->assertSee('Network')->assertSee('Configure switch')->assertSee('No Workstream')->assertSee('Overdue')->assertSee('Awaiting ISP handoff')
            ->assertSee('Cutover')->assertSee($ticket->ticket_number)->assertSee('1 Visit')->assertSee('rack-elevation.pdf')->assertSee('Approved elevation')->assertSee('Use the approved rack elevation.')
            ->assertDontSee('secret/project-object-key.pdf')->assertDontSee('PROJECT-AUDIT-SECRET')->assertDontSee('Notes / Activity')->assertDontSee('Office navigation');
        $this->assertLessThanOrEqual(32, $queryCount, "Project Workbook used {$queryCount} queries");
    }

    public function test_internal_project_workbook_and_project_authorization_are_safe(): void
    {
        [$organization, $reviewer] = $this->member('reviewer');
        [, $technician] = $this->member('technician', $organization);
        [, $otherAdmin] = $this->member('super_admin');
        $project = Project::factory()->create(['organization_id' => $organization->id, 'customer_id' => null, 'service_location_id' => null, 'primary_contact_id' => null, 'type' => 'internal', 'project_number' => 'NDT-PRJ-2026-0099', 'name' => 'Internal Lab', 'status' => 'planning']);

        $this->actingAs($reviewer)->get(route('office.projects.workbook.print', $project))->assertOk()->assertSee('Internal Project')->assertDontSee('Unavailable customer');
        $this->actingAs($technician)->get(route('office.projects.workbook.print', $project))->assertForbidden();
        $this->actingAs($otherAdmin)->get(route('office.projects.workbook.print', $project))->assertNotFound();
    }

    public function test_detail_pages_offer_print_actions_without_new_write_authority(): void
    {
        [$organization, $reviewer] = $this->member('reviewer');
        [$ticket] = $this->plainTicket($organization);
        $project = Project::factory()->create(['organization_id' => $organization->id, 'project_number' => 'NDT-PRJ-2026-0100']);

        $this->actingAs($reviewer)->get(route('office.service-tickets.show', $ticket))->assertOk()->assertSee('Documents')->assertSee('Technician Work Order')->assertSee('Completion Summary')->assertSee('Customer Service Record')->assertSee('Detailed Service Report')->assertDontSee('Edit ticket');
        $this->actingAs($reviewer)->get(route('office.projects.show', $project))->assertOk()->assertSee('Print Project Workbook')->assertDontSee('Edit Project');
    }

    /** @return array{Organization, User, OrganizationMembership} */
    private function member(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$organization, $user, $membership];
    }

    private function plainTicket(Organization $organization): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Acme Dental']);
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Jordan Customer', 'phone' => '555-0100', 'email' => 'jordan@example.test']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Main Office', 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-9001', 'title' => 'Replace network switch', 'description' => 'Replace and configure the failed access switch.', 'customer_visible_summary' => 'Restore office connectivity.', 'priority' => 'high', 'source' => 'phone', 'purpose' => 'service_call', 'billing_disposition' => 'billable', 'status' => 'open']);

        return [$ticket, $contact];
    }

    private function ticketScenario(Organization $organization, User $admin, OrganizationMembership $membership): array
    {
        [$ticket] = $this->plainTicket($organization);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $ticket->service_location_id, 'status' => 'pending_closeout', 'timezone' => 'America/Chicago', 'scheduled_start_at' => now()->subHours(3), 'scheduled_end_at' => now()->subHour(), 'en_route_at' => now()->subHours(3), 'en_route_by_id' => $admin->id, 'on_site_at' => now()->subHours(2), 'on_site_by_id' => $admin->id, 'created_by_id' => $admin->id]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true, 'assigned_by_id' => $admin->id]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'status' => 'submitted', 'outcome' => 'resolved', 'diagnosis' => 'Failed switch power supply.', 'work_performed' => 'Connectivity restored', 'recommendations' => 'Monitor for 24 hours.', 'representative_name' => 'Jordan Customer', 'acknowledged_at' => now(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'on_site', 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(), 'source' => 'timer']);

        return [$ticket->fresh(['customer', 'contact', 'serviceLocation']), $visit, $closeout];
    }
}
