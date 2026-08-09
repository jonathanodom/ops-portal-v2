<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BillingHandoff;
use App\Models\Capability;
use App\Models\Closeout;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VisitArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_can_archive_an_unused_visit_and_operational_queries_exclude_it(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph('scheduled');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);

        $this->actingAs($dispatcher)->get('/office/admin/archive')->assertForbidden();
        $this->actingAs($dispatcher)->post("/office/admin/archive/visits/{$visit->id}", ['reason' => 'Duplicate return trip', 'confirm_archive' => 1])->assertForbidden();
        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}")->assertOk()->assertSee('Archive visit');
        $this->actingAs($admin)->post("/office/admin/archive/visits/{$visit->id}", ['reason' => 'Duplicate return trip'])->assertSessionHasErrors('confirm_archive');

        $this->actingAs($admin)->post("/office/admin/archive/visits/{$visit->id}", [
            'reason' => 'Duplicate return trip',
            'confirm_archive' => 1,
        ])->assertRedirect("/office/service-tickets/{$ticket->id}")->assertSessionHasNoErrors();

        $this->assertSoftDeleted('visits', ['id' => $visit->id, 'archived_by_id' => $admin->id]);
        $this->assertSame(0, $ticket->visits()->count());
        $this->actingAs($admin)->get("/office/service-tickets/{$ticket->id}")->assertOk()->assertDontSee("Visit #{$visit->id}");
        $this->actingAs($admin)->get('/office/dispatch')->assertOk()->assertSee('0 visits');
        $this->actingAs($admin)->get('/office/admin/archive')->assertOk()->assertSee("Visit #{$visit->id}")->assertSee('Duplicate return trip');

        $metadata = AuditEvent::query()->where('event_type', 'visit.archived')->firstOrFail()->metadata;
        $this->assertSame($visit->id, $metadata['visit_id']);
        $this->assertStringNotContainsString('Duplicate return trip', json_encode($metadata));
    }

    public function test_archive_rechecks_status_timers_submitted_closeouts_and_return_children(): void
    {
        [$organization, $ticket, $active] = $this->ticketGraph('en_route');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->archive($admin, $active)->assertSessionHasErrors('status');

        $timerVisit = $this->visit($ticket, 'canceled');
        $timerCloseout = $this->closeout($timerVisit, 'draft');
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $timerVisit->id, 'closeout_id' => $timerCloseout->id, 'user_id' => $admin->id, 'active_user_id' => $admin->id, 'category' => 'travel', 'started_at' => now()]);
        $this->archive($admin, $timerVisit)->assertSessionHasErrors('active_timers');

        $submittedVisit = $this->visit($ticket, 'canceled');
        $this->closeout($submittedVisit, 'submitted');
        $this->archive($admin, $submittedVisit)->assertSessionHasErrors('closeout');

        $source = $this->visit($ticket, 'canceled');
        $this->visit($ticket, 'planned', $source->id);
        $this->archive($admin, $source)->assertSessionHasErrors('return_visits');

        foreach (['status', 'active_timers', 'closeout', 'return_visits'] as $field) {
            $this->assertTrue(AuditEvent::query()->where('event_type', 'visit.archive_rejected')->get()->contains(fn (AuditEvent $event) => in_array($field, $event->metadata['invalid_fields'], true)));
        }
    }

    public function test_restore_rules_preserve_canceled_history_and_validate_ticket_and_assignments(): void
    {
        [$organization, $ticket, $visit] = $this->ticketGraph('assigned');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [, , $membership] = $this->userWithRole('technician', $organization);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        $this->archive($admin, $visit)->assertRedirect();

        $this->actingAs($admin)->post("/office/admin/archive/visits/{$visit->id}/restore")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(Visit::query()->find($visit->id));
        $this->assertSame($admin->id, $visit->fresh()->restored_by_id);

        $this->archive($admin, $visit)->assertRedirect();
        $ticket->update(['status' => 'completed']);
        $this->actingAs($admin)->post("/office/admin/archive/visits/{$visit->id}/restore")->assertSessionHasErrors('visit');

        $canceled = $this->visit($ticket, 'canceled');
        $this->archive($admin, $canceled)->assertRedirect();
        $this->actingAs($admin)->post("/office/admin/archive/visits/{$canceled->id}/restore")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('canceled', $canceled->fresh()->status);

        $ticket->update(['status' => 'open']);
        $membership->update(['status' => 'inactive']);
        $this->actingAs($admin)->post("/office/admin/archive/visits/{$visit->id}/restore")->assertSessionHasErrors('assignments');
    }

    public function test_permanent_delete_requires_exact_id_and_refuses_any_operational_evidence(): void
    {
        [$organization, $ticket, $clean] = $this->ticketGraph('planned');
        [$admin] = $this->userWithRole('super_admin', $organization);
        $this->archive($admin, $clean)->assertRedirect();

        $this->actingAs($admin)->delete("/office/admin/archive/visits/{$clean->id}", ['confirm_visit_id' => $clean->id + 1])->assertSessionHasErrors('confirm_visit_id');
        $this->actingAs($admin)->delete("/office/admin/archive/visits/{$clean->id}", ['confirm_visit_id' => $clean->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('visits', ['id' => $clean->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'visit.purged', 'subject_id' => $ticket->id]);

        $evidenceVisit = $this->visit($ticket, 'canceled');
        $this->closeout($evidenceVisit, 'draft');
        $this->archive($admin, $evidenceVisit)->assertRedirect();
        $this->actingAs($admin)->get('/office/admin/archive')->assertOk()->assertSee('Permanent deletion unavailable');
        $this->actingAs($admin)->delete("/office/admin/archive/visits/{$evidenceVisit->id}", ['confirm_visit_id' => $evidenceVisit->id])->assertSessionHasErrors('visit');
        $this->assertSoftDeleted('visits', ['id' => $evidenceVisit->id]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'visit.purge_rejected']);
    }

    public function test_archived_accidental_return_no_longer_blocks_final_resolved_completion(): void
    {
        [$organization, $ticket, $finalVisit] = $this->ticketGraph('pending_closeout');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $closeout = $this->closeout($finalVisit, 'submitted', 'resolved');
        $return = $this->visit($ticket, 'planned', $finalVisit->id);
        $this->archive($admin, $return)->assertRedirect();

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()->assertSee('This is the final resolved visit.');
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('completed', $ticket->fresh()->status);
        $this->assertDatabaseHas('billing_handoffs', ['service_ticket_id' => $ticket->id, 'visit_id' => $finalVisit->id]);
    }

    public function test_archiving_the_last_blocker_completes_an_already_approved_resolved_ticket(): void
    {
        [$organization, $ticket, $finalVisit] = $this->ticketGraph('pending_closeout');
        [$admin] = $this->userWithRole('super_admin', $organization);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $closeout = $this->closeout($finalVisit, 'submitted', 'resolved');
        $return = $this->visit($ticket, 'planned', $finalVisit->id);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseMissing('billing_handoffs', ['service_ticket_id' => $ticket->id]);

        $this->archive($admin, $return)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('completed', $ticket->fresh()->status);
        $this->assertDatabaseHas('billing_handoffs', ['service_ticket_id' => $ticket->id, 'visit_id' => $finalVisit->id, 'closeout_id' => $closeout->id]);
        $this->assertSame(1, BillingHandoff::query()->where('service_ticket_id', $ticket->id)->count());
        $this->assertDatabaseHas('audit_events', ['event_type' => 'service_ticket.completed', 'subject_id' => $ticket->id]);
    }

    public function test_archive_endpoints_are_organization_scoped_and_honor_explicit_denial(): void
    {
        [$organization, , $visit] = $this->ticketGraph('planned');
        [$admin, , $membership] = $this->userWithRole('super_admin', $organization);
        $foreign = Organization::factory()->create();
        [, , $foreignVisit] = $this->ticketGraph('planned', $foreign);

        $this->archive($admin, $foreignVisit)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['event_type' => 'security.cross_organization_record_denied', 'subject_id' => $organization->id]);

        $capability = Capability::query()->where('key', 'visits.archive.manage')->firstOrFail();
        $membership->capabilityOverrides()->attach($capability, ['effect' => 'deny']);
        $this->actingAs($admin)->get('/office/admin/archive')->assertForbidden();
        $this->archive($admin, $visit)->assertForbidden();

        [$inactiveAdmin, , $inactiveMembership] = $this->userWithRole('super_admin', $organization);
        $inactiveMembership->update(['status' => 'inactive']);
        $this->actingAs($inactiveAdmin)->get('/office/admin/archive')->assertForbidden();
    }

    private function archive(User $user, Visit $visit)
    {
        return $this->actingAs($user)->post("/office/admin/archive/visits/{$visit->id}", [
            'reason' => 'Administrative cleanup',
            'confirm_archive' => 1,
        ]);
    }

    private function ticketGraph(string $status, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $contact = Contact::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Archive Contact', 'is_preferred' => true, 'active' => true]);
        $location = ServiceLocation::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Archive Site', 'address_line_1' => '100 Main', 'city' => 'Fort Worth', 'state' => 'TX', 'postal_code' => '76102', 'timezone' => 'America/Chicago', 'is_primary' => true, 'active' => true]);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT), 'title' => 'Archive workflow', 'description' => 'Test visit archive', 'priority' => 'normal', 'source' => 'internal', 'status' => 'open']);
        $visit = $this->visit($ticket, $status);

        return [$organization, $ticket, $visit];
    }

    private function visit(ServiceTicket $ticket, string $status, ?int $returnOf = null): Visit
    {
        return Visit::query()->create(['organization_id' => $ticket->organization_id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $ticket->service_location_id, 'return_of_visit_id' => $returnOf, 'status' => $status, 'timezone' => 'America/Chicago', 'scheduled_start_at' => in_array($status, ['scheduled', 'assigned'], true) ? now()->addDay() : null, 'scheduled_end_at' => in_array($status, ['scheduled', 'assigned'], true) ? now()->addDay()->addHour() : null]);
    }

    private function closeout(Visit $visit, string $status, ?string $outcome = null): Closeout
    {
        $closeout = Closeout::query()->create(['organization_id' => $visit->organization_id, 'visit_id' => $visit->id, 'version' => 1, 'status' => $status, 'content_version' => 1, 'outcome' => $outcome, 'diagnosis' => 'Preserved diagnosis', 'work_performed' => 'Preserved work', 'ack_unavailable_category' => 'remote_service', 'ack_unavailable_detail' => 'Preserved fallback', 'no_photo_category' => 'not_applicable', 'no_photo_detail' => 'Preserved reason', 'submitted_at' => $status === 'submitted' ? now() : null]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return $closeout;
    }

    private function userWithRole(string $roleKey, Organization $organization): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
