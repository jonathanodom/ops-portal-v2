<?php

namespace Tests\Feature;

use App\Domain\CloseoutReadiness;
use App\Domain\ServiceTicketCompletion;
use App\Domain\ServiceTicketWorkItemWorkflow;
use App\Models\AuditEvent;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceTicketWorkItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_assigned_field_user_can_record_and_disposition_additional_work_on_an_active_draft(): void
    {
        [$organization, $ticket, $visit, $technician] = $this->fieldGraph();

        $this->actingAs($technician)->post("/field/visits/{$visit->id}/work-items", [
            'title' => 'Replace damaged wall plate',
            'detail' => 'Discovered behind the display.',
            'work_note' => 'Replacement is available on the truck.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = ServiceTicketWorkItem::query()->firstOrFail();
        $this->assertSame('field_discovered', $item->origin);
        $this->assertSame('open', $item->status);
        $this->assertSame($visit->id, $item->discovered_visit_id);
        $this->assertDatabaseHas('service_ticket_work_item_visit', [
            'organization_id' => $organization->id,
            'service_ticket_work_item_id' => $item->id,
            'visit_id' => $visit->id,
            'first_touched_by_id' => $technician->id,
        ]);

        $this->actingAs($technician)->put("/field/visits/{$visit->id}/work-items/{$item->id}", [
            'status' => 'completed',
            'work_note' => 'Wall plate replaced and verified.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('completed', $item->fresh()->status);
        $event = AuditEvent::query()->where('subject_type', ServiceTicket::class)->where('subject_id', $ticket->id)
            ->where('event_type', 'service_ticket.work_item_status_changed')->firstOrFail();
        $this->assertSame($item->id, $event->metadata['work_item_id']);
        $this->assertStringNotContainsString('Wall plate replaced', json_encode($event->metadata));
    }

    public function test_field_work_is_blocked_before_on_site_without_a_draft_and_for_unassigned_users(): void
    {
        [$organization, , $visit, $technician] = $this->fieldGraph();
        [$other] = $this->userWithRole('technician', $organization);
        $payload = ['title' => 'Unexpected cable repair'];

        $visit->update(['status' => 'en_route']);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/work-items", $payload)
            ->assertRedirect()->assertSessionHasErrors('visit');

        $visit->update(['status' => 'on_site', 'current_closeout_id' => null]);
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/work-items", $payload)
            ->assertRedirect()->assertSessionHasErrors('visit');

        $this->actingAs($other)->post("/field/visits/{$visit->id}/work-items", $payload)->assertForbidden();
        $this->assertDatabaseCount('service_ticket_work_items', 0);
    }

    public function test_only_open_items_touched_on_the_current_visit_block_closeout_submission(): void
    {
        [, $ticket, $currentVisit, $technician, $closeout] = $this->fieldGraph();
        $otherVisit = Visit::query()->create([
            'organization_id' => $ticket->organization_id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $ticket->service_location_id,
            'status' => 'on_site',
            'timezone' => 'America/Chicago',
        ]);
        $otherCloseout = Closeout::query()->create([
            'organization_id' => $ticket->organization_id,
            'visit_id' => $otherVisit->id,
            'version' => 1,
            'status' => 'draft',
            'content_version' => 1,
        ]);
        $otherVisit->update(['current_closeout_id' => $otherCloseout->id]);
        $workflow = app(ServiceTicketWorkItemWorkflow::class);
        $untouched = $workflow->createFromOffice($ticket, $technician, [
            'title' => 'Office-added pending scope', 'status' => 'open',
        ]);
        $other = $workflow->createFromOffice($ticket, $technician, [
            'title' => 'Handled on another visit', 'status' => 'open',
        ]);
        $workflow->updateFromField($other, $otherVisit, $technician, ['status' => 'open', 'work_note' => null]);

        $this->assertArrayNotHasKey('work_items', app(CloseoutReadiness::class)->errors($closeout));

        $workflow->updateFromField($untouched, $currentVisit, $technician, ['status' => 'open', 'work_note' => null]);
        $this->assertArrayHasKey('work_items', app(CloseoutReadiness::class)->errors($closeout));

        $workflow->updateFromField($untouched, $currentVisit, $technician, ['status' => 'needs_follow_up', 'work_note' => 'Requires office action']);
        $this->assertArrayNotHasKey('work_items', app(CloseoutReadiness::class)->errors($closeout));
    }

    public function test_open_and_needs_follow_up_items_block_ticket_completion_until_terminal(): void
    {
        [$organization, $ticket, $visit, $reviewer, $closeout] = $this->reviewGraph();
        $item = app(ServiceTicketWorkItemWorkflow::class)->createFromOffice($ticket, $reviewer, [
            'title' => 'Additional remediation', 'status' => 'open',
        ]);

        $this->assertNull(app(ServiceTicketCompletion::class)->completeIfEligible($ticket, $reviewer, $closeout, $closeout->reviews->first()));
        $this->assertSame('open', $ticket->fresh()->status);

        app(ServiceTicketWorkItemWorkflow::class)->updateFromOffice($item, $reviewer, [
            'title' => $item->title, 'detail' => null, 'work_note' => null, 'status' => 'needs_follow_up',
        ]);
        $this->assertSame('open', $ticket->fresh()->status);

        app(ServiceTicketWorkItemWorkflow::class)->updateFromOffice($item, $reviewer, [
            'title' => $item->title, 'detail' => null, 'work_note' => null, 'status' => 'completed',
        ]);
        $this->assertSame('completed', $ticket->fresh()->status);
        $this->assertDatabaseHas('billing_handoffs', ['service_ticket_id' => $ticket->id, 'closeout_id' => $closeout->id]);
    }

    public function test_office_transfer_creates_one_canonical_follow_up_ticket_and_preserves_provenance(): void
    {
        [$organization, $ticket, , $admin] = $this->fieldGraph('super_admin');
        $item = app(ServiceTicketWorkItemWorkflow::class)->createFromOffice($ticket, $admin, [
            'title' => 'Return with replacement switch',
            'detail' => 'Further work is required.',
            'work_note' => 'Schedule after material arrives.',
            'status' => 'needs_follow_up',
        ]);
        $payload = ['priority' => 'high', 'purpose' => 'callback', 'billing_disposition' => 'warranty'];

        $first = app(ServiceTicketWorkItemWorkflow::class)->transfer($item, $organization, $admin, $payload);
        $second = app(ServiceTicketWorkItemWorkflow::class)->transfer($item->fresh(), $organization, $admin, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('transferred', $item->fresh()->status);
        $this->assertSame($first->id, $item->fresh()->follow_up_service_ticket_id);
        $this->assertSame($ticket->customer_id, $first->customer_id);
        $this->assertSame($ticket->service_location_id, $first->service_location_id);
        $this->assertSame('internal', $first->source);
        $this->assertSame('callback', $first->purpose);
        $this->assertSame('warranty', $first->billing_disposition);
        $this->assertDatabaseCount('service_tickets', 2);
        $this->assertDatabaseCount('visits', 1);
    }

    public function test_office_and_field_views_are_scoped_and_follow_existing_capabilities(): void
    {
        [$organization, $ticket, $visit, $admin] = $this->fieldGraph('super_admin');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$outsider, $otherOrganization] = $this->userWithRole('super_admin');
        app(ServiceTicketWorkItemWorkflow::class)->createFromOffice($ticket, $admin, [
            'title' => 'Documented additional work', 'status' => 'open',
        ]);

        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}")
            ->assertOk()->assertSee('Work Items')->assertSee('Documented additional work')->assertSee('Add Work Item');
        $this->actingAs($reviewer)->get("/office/service-tickets/{$ticket->id}")
            ->assertOk()->assertSee('Documented additional work')->assertDontSee('Add Work Item');
        $this->actingAs($admin)->get("/field/visits/{$visit->id}")
            ->assertOk()->assertSee('Primary scope')->assertSee('Additional work')->assertSee('Work Items');
        $this->actingAs($outsider)->get("/office/service-tickets/{$ticket->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $otherOrganization->id,
            'event_type' => 'security.cross_organization_record_denied',
        ]);
    }

    public function test_review_home_and_work_order_present_bounded_work_item_context(): void
    {
        [$organization, $ticket, $visit, $technician, $closeout] = $this->fieldGraph();
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $item = app(ServiceTicketWorkItemWorkflow::class)->createFromOffice($ticket, $admin, [
            'title' => 'Camera C-14 remains offline',
            'detail' => 'Additional device discovered during service.',
            'status' => 'open',
        ]);
        app(ServiceTicketWorkItemWorkflow::class)->updateFromField($item, $visit, $technician, [
            'status' => 'needs_follow_up',
            'work_note' => 'Office must schedule replacement work.',
        ]);
        $closeout->update([
            'status' => 'submitted',
            'submitted_token' => (string) Str::uuid(),
            'submitted_by_id' => $technician->id,
            'submitted_at' => now(),
        ]);
        $visit->update(['status' => 'pending_closeout']);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('Work Items handled this Visit')
            ->assertSee('Camera C-14 remains offline')
            ->assertSee('Approval will not close the Service Ticket yet.');
        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}/print")
            ->assertOk()
            ->assertSee('Additional Work Items')
            ->assertSee('Camera C-14 remains offline');
        $this->actingAs($admin)->get('/office')
            ->assertOk()
            ->assertSee('Work Item needs follow-up')
            ->assertSee('Camera C-14 remains offline');
    }

    public function test_work_item_remains_single_ticket_record_across_closeout_correction_versions(): void
    {
        [$organization, $ticket, $visit, $technician, $first] = $this->fieldGraph();
        $item = app(ServiceTicketWorkItemWorkflow::class)->createFromField($visit, $technician, [
            'title' => 'Additional jack repair',
        ]);
        $first->update(['status' => 'submitted', 'submitted_at' => now()]);
        $second = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'parent_closeout_id' => $first->id,
            'version' => 2,
            'status' => 'draft',
            'content_version' => 1,
        ]);
        $visit->update(['status' => 'returned_for_correction', 'current_closeout_id' => $second->id]);

        $this->assertDatabaseCount('service_ticket_work_items', 1);
        $this->assertSame($ticket->id, $item->fresh()->service_ticket_id);
        $this->assertSame([$visit->id], $item->fresh()->visits()->pluck('visits.id')->all());
        $this->assertSame(2, Closeout::query()->where('visit_id', $visit->id)->count());
    }

    /** @return array{Organization, ServiceTicket, Visit, User, Closeout} */
    private function fieldGraph(string $role = 'technician'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$actor, , $membership] = $this->userWithRole($role, $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'timezone' => 'America/Chicago',
        ]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'TEST-ST-2026-'.str_pad((string) $organization->id, 4, '0', STR_PAD_LEFT),
            'title' => 'Work Item field test',
            'description' => 'Primary ticket scope',
            'priority' => 'normal',
            'source' => 'phone',
            'purpose' => 'service_call',
            'billing_disposition' => 'billable',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'on_site',
            'timezone' => 'America/Chicago',
        ]);
        VisitAssignment::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'organization_membership_id' => $membership->id,
            'is_lead' => true,
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'draft',
            'content_version' => 1,
            'outcome' => 'resolved',
            'diagnosis' => 'Validated fault',
            'work_performed' => 'Restored service',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Remote approval',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'No visible change',
        ]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return [$organization, $ticket, $visit, $actor, $closeout];
    }

    /** @return array{Organization, ServiceTicket, Visit, User, Closeout} */
    private function reviewGraph(): array
    {
        [$organization, $ticket, $visit] = $this->fieldGraph();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $closeout = $visit->currentCloseout;
        $closeout->update([
            'status' => 'submitted',
            'submitted_token' => (string) Str::uuid(),
            'submitted_at' => now(),
        ]);
        $visit->update(['status' => 'approved']);
        CloseoutReview::query()->create([
            'organization_id' => $organization->id,
            'closeout_id' => $closeout->id,
            'decision' => 'approved',
            'decision_token' => (string) Str::uuid(),
            'reviewer_id' => $reviewer->id,
            'decided_at' => now(),
        ]);

        return [$organization, $ticket, $visit, $reviewer, $closeout->refresh()->load('reviews')];
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
