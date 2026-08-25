<?php

namespace Tests\Feature;

use App\Models\BillingHandoff;
use App\Models\Closeout;
use App\Models\CloseoutAcknowledgmentSignature;
use App\Models\CloseoutReview;
use App\Models\CloseoutReviewAdjustment;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\ServiceTicketFile;
use App\Models\ServiceTicketWorkItem;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitAssignment;
use App\Models\VisitMedia;
use App\Models\VisitPartProposal;
use App\Models\VisitTimeAllocation;
use App\Models\VisitTimeAllocationSet;
use App\Models\VisitTimeEntry;
use App\Models\VisitTimeEntryCorrection;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceTicketDocumentProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        Carbon::setTestNow('2026-08-25 18:00:00 UTC');
    }

    public function test_four_profiles_and_legacy_route_are_private_scoped_and_authorized(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [$ticket] = $this->scenario($organization, $admin, $membership);
        [, $technician] = $this->member('technician', $organization);
        [, $otherAdmin] = $this->member('super_admin');

        $routes = [
            'office.service-tickets.documents.technician-work-order' => 'TECHNICIAN WORK ORDER',
            'office.service-tickets.documents.completion-summary' => 'COMPLETION SUMMARY',
            'office.service-tickets.documents.customer-service-record' => 'CUSTOMER SERVICE RECORD',
            'office.service-tickets.documents.detailed-service-report' => 'DETAILED SERVICE REPORT',
        ];
        foreach ($routes as $route => $heading) {
            $this->actingAs($admin)->get(route($route, $ticket))->assertOk()
                ->assertHeader('cache-control', 'no-store, private')->assertHeader('x-content-type-options', 'nosniff')
                ->assertHeader('x-robots-tag', 'noindex, nofollow')->assertHeader('referrer-policy', 'no-referrer')
                ->assertSee($heading);
            $this->actingAs($technician)->get(route($route, $ticket))->assertForbidden();
            $this->actingAs($otherAdmin)->get(route($route, $ticket))->assertNotFound();
        }
        $this->actingAs($admin)->get(route('office.service-tickets.print', $ticket))->assertOk()->assertSee('TECHNICIAN WORK ORDER');
        $this->get('/office/service-tickets/'.$ticket->id.'/documents/not-a-template')->assertNotFound();
    }

    public function test_customer_profiles_use_effective_site_window_signature_and_safe_projection(): void
    {
        Storage::fake('local');
        [$organization, $admin, $membership] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        [$ticket, $visit, $closeout] = $this->scenario($organization, $admin, $membership);
        $entry = $visit->timeEntries()->firstOrFail();
        $entry->update(['corrected_started_at' => Carbon::parse('2026-08-25 14:15:00 UTC'), 'corrected_ended_at' => Carbon::parse('2026-08-25 15:45:00 UTC')]);
        $signature = $this->signature($organization, $closeout, $admin);
        $workItem = ServiceTicketWorkItem::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'discovered_visit_id' => $visit->id, 'origin' => 'field_discovered', 'title' => 'Replace damaged patch lead', 'detail' => 'INTERNAL-DETAIL-SECRET', 'work_note' => 'INTERNAL-WORK-NOTE', 'status' => 'completed', 'created_by_id' => $admin->id]);
        $workItem->visits()->attach($visit->id, ['organization_id' => $organization->id, 'first_touched_by_id' => $admin->id, 'first_touched_at' => now(), 'last_touched_by_id' => $admin->id, 'last_touched_at' => now()]);
        ServiceTicketFile::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'uploaded_by_id' => $admin->id, 'storage_disk' => 'local', 'storage_key' => 'private/SECRET-KEY.pdf', 'original_name' => 'INTERNAL-FILE.pdf', 'mime_type' => 'application/pdf', 'byte_size' => 100, 'state' => 'stored']);

        foreach (['office.service-tickets.documents.completion-summary', 'office.service-tickets.documents.customer-service-record'] as $route) {
            $response = $this->actingAs($admin)->get(route($route, $ticket))->assertOk()
                ->assertSee('Aug 25, 2026 9:15 AM')->assertSee('10:45 AM CDT')->assertSee('1 hr 30 min elapsed')
                ->assertSee('Replace damaged patch lead')->assertSeeText('Signed on-site by: Jordan Customer')->assertSee('Acknowledgment signature for Jordan Customer')
                ->assertDontSee('En route')->assertDontSee('Billing disposition')->assertDontSee('Operational Work-Time Attribution')
                ->assertDontSee('INTERNAL-DETAIL-SECRET')->assertDontSee('INTERNAL-WORK-NOTE')->assertDontSee('INTERNAL-FILE.pdf')->assertDontSee('SECRET-KEY');
            $reviewerHtml = $this->actingAs($reviewer)->get(route($route, $ticket))->assertOk()->getContent();
            $this->assertStringContainsString('Jordan Customer', $reviewerHtml);
            $this->assertStringNotContainsString('INTERNAL-WORK-NOTE', $reviewerHtml);
        }
        $this->actingAs($reviewer)->get(route('closeout-acknowledgment-signatures.show', $signature))->assertOk()->assertHeader('cache-control', 'no-store, private');
        [, $billing] = $this->member('billing', $organization);
        $this->actingAs($billing)->get(route('closeout-acknowledgment-signatures.show', $signature))->assertForbidden();
        $this->actingAs($billing)->get(route('office.service-tickets.documents.signature', [$ticket, $signature]))->assertOk()->assertHeader('cache-control', 'no-store, private');
    }

    public function test_detailed_report_separates_factual_review_and_attribution_and_gates_billing(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [, $reviewer] = $this->member('reviewer', $organization);
        [$ticket, $visit, $closeout] = $this->scenario($organization, $admin, $membership);
        $entry = $visit->timeEntries()->firstOrFail();
        $entry->update(['corrected_started_at' => Carbon::parse('2026-08-25 14:15:00 UTC'), 'corrected_ended_at' => Carbon::parse('2026-08-25 15:45:00 UTC')]);
        VisitTimeEntryCorrection::query()->create(['organization_id' => $organization->id, 'visit_time_entry_id' => $entry->id, 'sequence' => 1, 'previous_started_at' => $entry->started_at, 'previous_ended_at' => $entry->ended_at, 'corrected_started_at' => $entry->corrected_started_at, 'corrected_ended_at' => $entry->corrected_ended_at, 'reason' => 'CORRECTION-REASON-SECRET', 'corrected_by_id' => $admin->id]);
        $item = ServiceTicketWorkItem::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'origin' => 'office_added', 'title' => 'Access point follow-up', 'status' => 'open', 'created_by_id' => $admin->id]);
        $set = VisitTimeAllocationSet::query()->create(['organization_id' => $organization->id, 'visit_time_entry_id' => $entry->id, 'sequence' => 1, 'reason' => 'ALLOC-SECRET', 'allocated_by_id' => $admin->id]);
        VisitTimeAllocation::query()->create(['organization_id' => $organization->id, 'visit_time_allocation_set_id' => $set->id, 'service_ticket_work_item_id' => $item->id, 'allocated_seconds' => 1800, 'position' => 1]);
        $review = CloseoutReview::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'reviewer_id' => $admin->id, 'decision' => 'approved', 'reason' => 'Approved after review', 'decision_token' => 'review-token', 'decided_at' => now()]);
        CloseoutReviewAdjustment::query()->create(['organization_id' => $organization->id, 'closeout_review_id' => $review->id, 'type' => 'time', 'visit_time_entry_id' => $entry->id, 'excluded' => false, 'approved_minutes' => 75, 'reason' => 'Adjustment context']);
        BillingHandoff::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'status' => 'ready', 'approved_time_minutes' => 75, 'approved_parts_count' => 0, 'created_by_id' => $admin->id]);
        VisitMedia::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'uploader_id' => $admin->id, 'storage_disk' => 'local', 'storage_key' => 'PRIVATE-MEDIA-KEY', 'mime_type' => 'image/jpeg', 'byte_size' => 100, 'category' => 'after', 'caption' => 'Completed rack', 'state' => 'stored']);

        $this->actingAs($admin)->get(route('office.service-tickets.documents.detailed-service-report', $ticket))->assertOk()
            ->assertSee('Operational Work-Time Attribution — Not Billing Allocation')->assertSee('On Site')->assertSee('Corrected')
            ->assertSee('Recorded:')->assertSee('Access point follow-up')->assertSee('1 hr Unallocated')->assertSee('75 approved min')
            ->assertSee('On-site crew time')->assertSee('Site window:')->assertSee('Closeout v1')->assertSee('Approved after review')
            ->assertSee('Evidence Index')->assertSee('Completed rack')->assertSee('Billing Handoff:')->assertDontSee('PRIVATE-MEDIA-KEY')
            ->assertDontSee('CORRECTION-REASON-SECRET')->assertDontSee('ALLOC-SECRET');

        $this->actingAs($reviewer)->get(route('office.service-tickets.documents.detailed-service-report', $ticket))->assertOk()
            ->assertSee('Closeout v1')->assertDontSee('Billing Handoff:');
    }

    public function test_acknowledgment_is_owned_by_closeout_version_and_customer_record_uses_current_version(): void
    {
        Storage::fake('local');
        [$organization, $admin, $membership] = $this->member('super_admin');
        [$ticket, $visit, $first] = $this->scenario($organization, $admin, $membership);
        $this->signature($organization, $first, $admin);
        $second = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'parent_closeout_id' => $first->id, 'version' => 2, 'status' => 'submitted', 'outcome' => 'resolved', 'work_performed' => 'Corrected record.', 'representative_name' => 'Jordan Customer', 'representative_role' => 'Office manager', 'ack_unavailable_category' => 'remote_service', 'ack_unavailable_detail' => 'Reviewed remotely.', 'acknowledged_at' => now(), 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $second->id]);

        $this->actingAs($admin)->get(route('office.service-tickets.documents.customer-service-record', $ticket))->assertOk()
            ->assertSee('Remote service')->assertSee('Reviewed remotely.')->assertDontSee('Acknowledgment signature for Jordan Customer');
        $this->actingAs($admin)->get(route('office.service-tickets.documents.detailed-service-report', $ticket))->assertOk()
            ->assertSee('Closeout v1')->assertSee('Closeout v2')->assertSee('Acknowledgment signature for Jordan Customer')->assertSee('Remote service');
    }

    public function test_profile_queries_are_bounded(): void
    {
        [$organization, $admin, $membership] = $this->member('super_admin');
        [$ticket] = $this->scenario($organization, $admin, $membership);
        $budgets = [
            'office.service-tickets.documents.technician-work-order' => 26,
            'office.service-tickets.documents.completion-summary' => 22,
            'office.service-tickets.documents.customer-service-record' => 25,
            'office.service-tickets.documents.detailed-service-report' => 36,
        ];
        foreach ($budgets as $route => $budget) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get(route($route, $ticket))->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertLessThanOrEqual($budget, $count, "{$route} used {$count} queries");
        }
    }

    private function signature(Organization $organization, Closeout $closeout, User $actor): CloseoutAcknowledgmentSignature
    {
        Storage::disk('local')->put('ack/signature.png', 'png');

        return CloseoutAcknowledgmentSignature::query()->create(['organization_id' => $organization->id, 'closeout_id' => $closeout->id, 'signer_name' => 'Jordan Customer', 'signer_role' => 'Office manager', 'statement_version' => 'service-closeout-v1', 'statement_snapshot' => config('field_execution.ack_statement'), 'storage_disk' => 'local', 'storage_key' => 'ack/signature.png', 'mime_type' => 'image/png', 'size_bytes' => 3, 'sha256' => hash('sha256', 'png'), 'signed_at' => now(), 'captured_by_id' => $actor->id]);
    }

    private function scenario(Organization $organization, User $admin, OrganizationMembership $membership): array
    {
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Acme Dental']);
        $contact = Contact::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'name' => 'Jordan Customer']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'primary_contact_id' => $contact->id, 'name' => 'Main Office', 'timezone' => 'America/Chicago']);
        $ticket = ServiceTicket::query()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'service_location_id' => $location->id, 'contact_id' => $contact->id, 'ticket_number' => 'NDT-ST-2026-9901', 'title' => 'Restore office connectivity', 'description' => 'Diagnose the office network outage.', 'customer_visible_summary' => 'Restore reliable connectivity.', 'priority' => 'high', 'source' => 'phone', 'purpose' => 'service_call', 'billing_disposition' => 'billable', 'status' => 'completed']);
        $visit = Visit::query()->create(['organization_id' => $organization->id, 'service_ticket_id' => $ticket->id, 'service_location_id' => $location->id, 'status' => 'approved', 'timezone' => 'America/Chicago', 'scheduled_start_at' => Carbon::parse('2026-08-25 14:00:00 UTC'), 'scheduled_end_at' => Carbon::parse('2026-08-25 16:00:00 UTC'), 'created_by_id' => $admin->id]);
        VisitAssignment::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'organization_membership_id' => $membership->id, 'is_lead' => true, 'assigned_by_id' => $admin->id]);
        $closeout = Closeout::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'version' => 1, 'status' => 'submitted', 'outcome' => 'resolved', 'diagnosis' => 'Failed switch.', 'work_performed' => 'Connectivity restored.', 'recommendations' => 'Monitor service.', 'representative_name' => 'Jordan Customer', 'submitted_by_id' => $admin->id, 'submitted_at' => now()]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        VisitTimeEntry::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'user_id' => $admin->id, 'category' => 'on_site', 'started_at' => Carbon::parse('2026-08-25 14:00:00 UTC'), 'ended_at' => Carbon::parse('2026-08-25 16:00:00 UTC'), 'source' => 'timer']);
        VisitPartProposal::query()->create(['organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id, 'proposed_by_id' => $admin->id, 'description' => 'Network switch', 'quantity' => 1, 'unit' => 'each', 'billing_treatment' => 'billable']);

        return [$ticket, $visit, $closeout];
    }

    private function member(string $role, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create(['timezone' => 'America/Chicago']);
        $user = User::factory()->create();
        $membership = OrganizationMembership::query()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active']);
        $membership->roles()->attach(Role::query()->where('key', $role)->firstOrFail());

        return [$organization, $user, $membership];
    }
}
