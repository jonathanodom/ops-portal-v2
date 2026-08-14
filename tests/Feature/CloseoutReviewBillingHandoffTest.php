<?php

namespace Tests\Feature;

use App\Events\BillingHandoffCreated;
use App\Models\AuditEvent;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloseoutReviewBillingHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_return_creates_an_immutable_correction_version_with_copied_content_and_parts(): void
    {
        [$organization, $visit, $closeout, $technician] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $part = VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'proposed_by_id' => $technician->id, 'description' => 'Replacement switch', 'quantity' => 1, 'unit' => 'each', 'billing_treatment' => 'billable']);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $technician->id, 'category' => 'on_site', 'started_at' => now()->subHour(), 'ended_at' => now(), 'source' => 'timer']);
        VisitMedia::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'uploader_id' => $technician->id, 'storage_disk' => 'local', 'storage_key' => 'field-media/opaque.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 100, 'category' => 'after', 'state' => 'stored']);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('title="America/Chicago"', false);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/return", ['decision_token' => (string) Str::uuid(), 'reason' => 'Clarify the repair details.'])->assertRedirect()->assertSessionHasNoErrors();

        $next = $visit->fresh()->currentCloseout;
        $this->assertSame(2, $next->version);
        $this->assertSame($closeout->id, $next->parent_closeout_id);
        $this->assertSame($closeout->diagnosis, $next->diagnosis);
        $this->assertSame('returned_for_correction', $visit->fresh()->status);
        $this->assertDatabaseHas('visit_part_proposals', ['closeout_id' => $next->id, 'source_proposal_id' => $part->id]);
        $this->assertDatabaseCount('visit_time_entries', 1);
        $this->assertDatabaseCount('visit_media', 1);
        $this->actingAs($technician)->get("/field/visits/{$visit->id}")->assertOk()->assertSee('Returned for correction')->assertSee('Clarify the repair details')->assertSee('Inherited read-only evidence');
    }

    public function test_resolved_approval_applies_adjustments_completes_ticket_and_creates_one_handoff(): void
    {
        Event::fake([BillingHandoffCreated::class]);
        [$organization, $visit, $closeout, $technician] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $time = VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $technician->id, 'category' => 'on_site', 'started_at' => now()->subMinutes(90), 'ended_at' => now(), 'source' => 'timer']);
        $part = VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'proposed_by_id' => $technician->id, 'description' => 'Cable', 'quantity' => 2, 'unit' => 'feet', 'billing_treatment' => 'billable']);
        $token = (string) Str::uuid();
        $payload = [
            'decision_token' => $token,
            'time_adjustments' => [$time->id => ['enabled' => 1, 'approved_minutes' => 60, 'reason' => 'Remove lunch interval']],
            'part_adjustments' => [$part->id => ['enabled' => 1, 'approved_quantity' => 1, 'approved_unit' => 'each', 'approved_billing_treatment' => 'warranty', 'reason' => 'One assembly used']],
        ];

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('This is the final resolved visit.')
            ->assertSee('Approve closeout &amp; complete Service Ticket', false);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", $payload)
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Service Ticket is complete'));
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", $payload)->assertRedirect();

        $this->assertSame('approved', $visit->fresh()->status);
        $this->assertSame('completed', $visit->serviceTicket->fresh()->status);
        $this->assertDatabaseCount('closeout_reviews', 1);
        $this->assertDatabaseCount('billing_handoffs', 1);
        $this->assertDatabaseHas('billing_handoffs', ['service_ticket_id' => $visit->service_ticket_id, 'status' => 'ready', 'approved_time_minutes' => 60, 'approved_parts_count' => 1]);
        $this->assertSame('90', (string) ceil($time->started_at->diffInSeconds($time->ended_at) / 60));
        $this->assertSame('2.00', $part->fresh()->quantity);
        Event::assertDispatched(BillingHandoffCreated::class);
    }

    public function test_correction_resubmission_inherits_acknowledgment_and_photo_without_duplicate_return_work(): void
    {
        [$organization, $visit, $closeout, $technician] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $acknowledgedAt = now()->subDay()->startOfSecond();
        $closeout->update(['representative_name' => 'Customer Representative', 'acknowledged_at' => $acknowledgedAt, 'ack_unavailable_category' => null, 'ack_unavailable_detail' => null, 'no_photo_category' => null, 'no_photo_detail' => null]);
        VisitMedia::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'uploader_id' => $technician->id, 'storage_disk' => 'local', 'storage_key' => 'field-media/inherited.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 100, 'category' => 'after', 'state' => 'stored']);
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/return", ['decision_token' => (string) Str::uuid(), 'reason' => 'Clarify diagnosis'])->assertRedirect();
        $next = $visit->fresh()->currentCloseout;

        $this->actingAs($technician)->post("/field/visits/{$visit->id}/draft", ['content_version' => 1, 'outcome' => 'resolved', 'diagnosis' => 'Corrected diagnosis', 'work_performed' => $next->work_performed, 'representative_name' => $next->representative_name])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($technician)->post("/field/visits/{$visit->id}/submit", ['submission_token' => (string) Str::uuid(), 'acknowledgment_confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', $next->fresh()->status);
        $this->assertSame('pending_closeout', $visit->fresh()->status);
        $this->assertTrue($next->fresh()->acknowledged_at->equalTo($acknowledgedAt));
        $this->assertDatabaseCount('visit_media', 1);
    }

    public function test_follow_up_prevents_completion_and_non_resolved_outcomes_never_create_handoffs(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout('needs_return_trip');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $return = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $visit->service_ticket_id, 'service_location_id' => $visit->service_location_id, 'return_of_visit_id' => $visit->id, 'status' => 'planned', 'timezone' => $visit->timezone]);
        $closeout->update(['return_visit_id' => $return->id]);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('This Service Ticket will remain open.')
            ->assertSee($return->displayLabel());

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('open', $visit->serviceTicket->fresh()->status);
        $this->assertDatabaseCount('billing_handoffs', 0);
        $this->assertDatabaseCount('visits', 2);
    }

    public function test_final_resolved_return_trip_clearly_completes_a_multi_visit_ticket(): void
    {
        [$organization, $finalVisit, $closeout] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $firstVisit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $finalVisit->service_ticket_id, 'service_location_id' => $finalVisit->service_location_id, 'status' => 'approved', 'timezone' => $finalVisit->timezone]);
        $secondVisit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $finalVisit->service_ticket_id, 'service_location_id' => $finalVisit->service_location_id, 'return_of_visit_id' => $firstVisit->id, 'status' => 'approved', 'timezone' => $finalVisit->timezone]);
        $finalVisit->update(['return_of_visit_id' => $secondVisit->id]);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('This is the final resolved visit.')
            ->assertSee('Approving this closeout will complete');

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('completed', $finalVisit->serviceTicket->fresh()->status);
        $this->assertDatabaseCount('billing_handoffs', 1);
    }

    public function test_approval_order_does_not_leave_a_multi_visit_ticket_open(): void
    {
        [$organization, $firstVisit] = $this->submittedCloseout('needs_return_trip');
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $ticket = $firstVisit->serviceTicket;
        $finalVisit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $firstVisit->service_location_id,
            'status' => 'pending_closeout',
            'timezone' => 'America/Chicago',
        ]);
        $finalCloseout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $finalVisit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 2,
            'outcome' => 'resolved',
            'diagnosis' => 'Final diagnosis',
            'work_performed' => 'Final repair',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Customer unavailable for acknowledgment',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'Evidence not applicable',
            'submitted_token' => (string) Str::uuid(),
            'submitted_by_id' => $firstVisit->currentCloseout->submitted_by_id,
            'submitted_at' => now()->addMinute(),
        ]);
        $finalVisit->update(['current_closeout_id' => $finalCloseout->id]);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$finalCloseout->id}/approve", [
            'decision_token' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('open', $ticket->fresh()->status);

        $firstCloseout = $firstVisit->currentCloseout;
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$firstCloseout->id}/approve", [
            'decision_token' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('completed', $ticket->fresh()->status);
        $this->assertDatabaseHas('billing_handoffs', [
            'service_ticket_id' => $ticket->id,
            'visit_id' => $finalVisit->id,
            'closeout_id' => $finalCloseout->id,
        ]);
    }

    public function test_resolved_review_lists_visits_that_still_block_ticket_completion(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $blockingVisit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $visit->service_ticket_id, 'service_location_id' => $visit->service_location_id, 'return_of_visit_id' => $visit->id, 'status' => 'planned', 'timezone' => $visit->timezone]);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('Approval will not close the Service Ticket yet.')
            ->assertSee($blockingVisit->displayLabel())
            ->assertSee('Planned');

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'remains open'));

        $this->assertSame('open', $visit->serviceTicket->fresh()->status);
        $this->assertDatabaseCount('billing_handoffs', 0);
    }

    public function test_customer_unavailable_requires_and_applies_a_safe_disposition(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout('customer_unavailable');
        [$reviewer] = $this->userWithRole('reviewer', $organization);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertSessionHasErrors('disposition');
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid(), 'disposition' => 'follow_up', 'disposition_reason' => 'Private scheduling reason'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visits', ['return_of_visit_id' => $visit->id, 'status' => 'planned']);
        $this->assertSame('open', $visit->serviceTicket->fresh()->status);
        $this->assertDatabaseCount('billing_handoffs', 0);
        $this->assertStringNotContainsString('Private scheduling reason', AuditEvent::query()->get()->pluck('metadata')->toJson());
    }

    public function test_customer_unavailable_hold_and_cancel_dispositions_are_atomic(): void
    {
        [$holdOrganization, $holdVisit, $holdCloseout] = $this->submittedCloseout('customer_unavailable');
        [$holdReviewer] = $this->userWithRole('reviewer', $holdOrganization);
        $this->actingAs($holdReviewer)->post("/office/closeout-reviews/{$holdCloseout->id}/approve", ['decision_token' => (string) Str::uuid(), 'disposition' => 'hold', 'disposition_reason' => 'Await customer contact'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('on_hold', $holdVisit->serviceTicket->fresh()->status);

        [$cancelOrganization, $cancelVisit, $cancelCloseout] = $this->submittedCloseout('customer_unavailable');
        [$cancelReviewer] = $this->userWithRole('reviewer', $cancelOrganization);
        $planned = Visit::query()->create(['organization_id' => $cancelOrganization->id, 'service_ticket_id' => $cancelVisit->service_ticket_id, 'service_location_id' => $cancelVisit->service_location_id, 'status' => 'planned', 'timezone' => $cancelVisit->timezone]);
        $this->actingAs($cancelReviewer)->post("/office/closeout-reviews/{$cancelCloseout->id}/approve", ['decision_token' => (string) Str::uuid(), 'disposition' => 'cancel', 'disposition_reason' => 'Customer declined service'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('canceled', $cancelVisit->serviceTicket->fresh()->status);
        $this->assertSame('canceled', $planned->fresh()->status);
        $this->assertSame('customer_unavailable', $cancelVisit->fresh()->status);
    }

    public function test_only_super_admin_may_self_review_and_the_override_is_audited(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $closeout->update(['submitted_by_id' => $reviewer->id]);
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertForbidden();

        [$superAdmin] = $this->userWithRole('super_admin', $organization);
        $closeout->update(['submitted_by_id' => $superAdmin->id]);
        $this->actingAs($superAdmin)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('closeout_reviews', ['closeout_id' => $closeout->id, 'self_review_override' => true]);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'closeout.approved']);
    }

    public function test_billing_can_create_invoice_without_access_to_private_evidence(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$billing] = $this->userWithRole('billing', $organization);
        VisitMedia::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'storage_disk' => 'local', 'storage_key' => 'field-media/private.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 10, 'category' => 'after', 'state' => 'stored']);
        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()]);
        $handoff = $visit->serviceTicket->billingHandoff()->firstOrFail();

        $this->actingAs($billing)->get('/office/billing-handoffs')
            ->assertRedirect(route('office.invoices.index', ['workspace' => 'ready_to_invoice']));
        $this->actingAs($billing)->get(route('office.invoices.index', ['workspace' => 'ready_to_invoice']))
            ->assertOk()
            ->assertSee($visit->serviceTicket->ticket_number)
            ->assertDontSee('Sensitive diagnosis');
        $this->actingAs($billing)->get('/office/closeout-reviews')->assertForbidden();
        $this->actingAs($billing)->get('/field-media/'.VisitMedia::query()->value('id'))->assertForbidden();
        $this->actingAs($billing)->post("/office/billing-handoffs/{$handoff->id}/invoice", ['creation_token' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('billing_handoffs', ['id' => $handoff->id, 'status' => 'handed_off', 'handed_off_by_id' => $billing->id]);
        $this->assertDatabaseHas('invoices', ['billing_handoff_id' => $handoff->id, 'status' => 'draft']);
    }

    public function test_cross_organization_review_urls_return_not_found(): void
    {
        [, , $closeout] = $this->submittedCloseout();
        [$otherOrganization] = $this->submittedCloseout();
        [$outsider] = $this->userWithRole('reviewer', $otherOrganization);
        $this->actingAs($outsider)->get("/office/closeout-reviews/{$closeout->id}")->assertNotFound();
        $this->actingAs($outsider)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_review_and_billing_capability_matrix_is_enforced(): void
    {
        [$organization, , $closeout] = $this->submittedCloseout();
        [$dispatcher] = $this->userWithRole('dispatcher', $organization);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        [$billing] = $this->userWithRole('billing', $organization);
        [$technician] = $this->userWithRole('technician', $organization);

        $this->actingAs($dispatcher)->get('/office/closeout-reviews')->assertOk();
        $this->actingAs($dispatcher)->post("/office/closeout-reviews/{$closeout->id}/approve", ['decision_token' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($reviewer)->get('/office/closeout-reviews')->assertOk();
        $this->actingAs($billing)->get('/office/closeout-reviews')->assertForbidden();
        $this->actingAs($billing)->get('/office/billing-handoffs')
            ->assertRedirect(route('office.invoices.index', ['workspace' => 'ready_to_invoice']));
        $this->actingAs($billing)->get(route('office.invoices.index'))->assertOk();
        $this->actingAs($technician)->get('/office/billing-handoffs')->assertForbidden();
    }

    public function test_review_workspace_uses_responsive_queue_conventions(): void
    {
        [$organization, $visit, $closeout] = $this->submittedCloseout();
        [$reviewer] = $this->userWithRole('reviewer', $organization);

        $this->actingAs($reviewer)->get('/office/closeout-reviews')
            ->assertOk()
            ->assertSee('data-office-width="workspace"', false)
            ->assertSee('aria-label="Closeout review filters"', false)
            ->assertSee('data-office-table', false)
            ->assertSee('data-office-mobile-list', false)
            ->assertSee($visit->serviceTicket->ticket_number)
            ->assertSee('Submitted by')
            ->assertSee("Review<span class=\"sr-only\"> {$visit->serviceTicket->ticket_number}", false);
    }

    /** @return array{Organization, Visit, Closeout, User} */
    private function submittedCloseout(string $outcome = 'resolved'): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        [$technician, , $membership] = $this->userWithRole('technician', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'ticket_number' => 'NDT-ST-2026-'.str_pad((string) $organization->id, 4, '0', STR_PAD_LEFT), 'title' => 'Review test', 'description' => 'Private scope', 'priority' => 'normal', 'source' => 'phone', 'status' => $outcome === 'on_hold' ? 'on_hold' : 'open']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => $outcome === 'customer_unavailable' ? 'customer_unavailable' : 'pending_closeout', 'timezone' => 'America/Chicago']);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'content_version' => 2, 'outcome' => $outcome, 'diagnosis' => 'Sensitive diagnosis', 'work_performed' => 'Sensitive work', 'ack_unavailable_category' => 'remote_service', 'ack_unavailable_detail' => 'Private acknowledgment', 'no_photo_category' => 'not_applicable', 'no_photo_detail' => 'Private reason', 'unavailable_category' => $outcome === 'customer_unavailable' ? 'no_answer' : null, 'unavailable_detail' => $outcome === 'customer_unavailable' ? 'Private unavailable detail' : null, 'submitted_token' => (string) Str::uuid(), 'submitted_by_id' => $technician->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return [$organization, $visit, $closeout, $technician];
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
