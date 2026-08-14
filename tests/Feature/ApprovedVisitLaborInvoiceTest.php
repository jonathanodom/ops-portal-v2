<?php

namespace Tests\Feature;

use App\Domain\ApprovedVisitLaborWorkflow;
use App\Domain\InvoiceWorkflow;
use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\CatalogService;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\CloseoutReviewAdjustment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use App\Support\AuditRecorder;
use Carbon\Carbon;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovedVisitLaborInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_approved_reviewed_visit_labor_uses_phase_87_policy_catalog_snapshot_and_provenance(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $invoice = $this->directInvoice($organization, $admin, $customer, $location);
        [$visit, $closeout, $review] = $this->approvedVisit($organization, $admin, $customer, $location, 87, 10, 80);

        $this->actingAs($admin)->get("/office/invoices/{$invoice->id}")
            ->assertOk()
            ->assertSee('+ Add Approved Visit Labor')
            ->assertSee($visit->displayNumber())
            ->assertSee('90 approved labor minutes')
            ->assertSee('Unbilled');
        $this->actingAs($admin)->post("/office/invoices/{$invoice->id}/visit-labor/{$visit->id}", [
            'visit_labor_context' => '1',
        ])->assertRedirect("/office/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        $line = $invoice->lines()->where('source_visit_id', $visit->id)->sole();
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        $this->assertSame('labor', $line->line_type);
        $this->assertSame(1500, $line->quantity_millis);
        $this->assertSame(13500, $line->unit_price_cents);
        $this->assertSame(20250, $line->total_cents);
        $this->assertSame($visit->id, $line->source_visit_id);
        $this->assertSame($closeout->id, $line->source_closeout_id);
        $this->assertSame($review->id, $line->source_review_id);
        $this->assertSame($labor->id, $line->catalog_service_id);
        $this->assertSame('LABOR-BUS', $line->catalog_code_snapshot);
        $this->assertNull($line->labor_rate_id);
        $this->assertStringContainsString('Service Labor', $line->description);
        $this->assertStringNotContainsString('Visit #'.$visit->id, $line->description);
        $this->assertSame(20250, $invoice->fresh()->total_cents);
        $event = AuditEvent::query()->where('event_type', 'invoice.approved_visit_labor_added')->firstOrFail();
        $this->assertSame(90, $event->metadata['approved_minutes']);
        $this->assertSame(90, $event->metadata['billable_minutes']);
        $this->assertSame($labor->id, $event->metadata['catalog_service_id']);
        $this->assertSame('ready_for_review', app(InvoiceWorkflow::class)->markReady($invoice->fresh(), $admin)->status);
    }

    public function test_duplicate_labor_is_blocked_server_side_and_removing_editable_line_releases_eligibility(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $firstInvoice = $this->directInvoice($organization, $admin, $customer, $location);
        $secondInvoice = $this->directInvoice($organization, $admin, $customer, $location);
        [$visit] = $this->approvedVisit($organization, $admin, $customer, $location, 60);
        $workflow = app(ApprovedVisitLaborWorkflow::class);
        $firstLine = $workflow->attach($firstInvoice, $visit, $admin);

        $this->actingAs($admin)->from("/office/invoices/{$secondInvoice->id}")
            ->followingRedirects()
            ->post("/office/invoices/{$secondInvoice->id}/visit-labor/{$visit->id}", ['visit_labor_context' => '1'])
            ->assertOk()
            ->assertSee('already represented on '.$firstInvoice->invoice_number)
            ->assertSee('data-auto-open="true"', false);
        $this->assertSame(1, Invoice::query()->whereHas('lines', fn ($query) => $query->where('source_visit_id', $visit->id)->where('line_type', 'labor'))->count());

        app(InvoiceWorkflow::class)->removeLine($firstInvoice, $firstLine, $admin, 'Move this approved work to the correct direct invoice.');
        $candidates = $workflow->candidates($secondInvoice);
        $this->assertTrue($candidates['eligible']->contains(fn (array $candidate): bool => $candidate['visit']->is($visit)));
        $secondLine = $workflow->attach($secondInvoice, $visit, $admin);
        $this->assertSame($secondInvoice->id, $secondLine->invoice_id);

        $secondInvoice->update(['status' => 'issued', 'issued_at' => now(), 'issued_by_id' => $admin->id]);
        $thirdInvoice = $this->directInvoice($organization, $admin, $customer, $location);
        $this->assertTrue($workflow->candidates($thirdInvoice)['billed']->contains(fn (array $candidate): bool => $candidate['visit']->is($visit)));
    }

    public function test_candidate_query_excludes_wrong_context_unapproved_unsubmitted_unreviewed_and_zero_labor_visits(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $invoice = $this->directInvoice($organization, $admin, $customer, $location);
        [$eligible] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        [$unapproved] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        $unapproved->update(['status' => 'pending_closeout']);
        [$unsubmitted, $unsubmittedCloseout] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        $unsubmittedCloseout->update(['status' => 'draft']);
        [$unreviewed, , $unreviewedReview] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        $unreviewedReview->update(['decision' => 'returned']);
        [$zeroLabor] = $this->approvedVisit($organization, $admin, $customer, $location, 0);
        $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
        $otherLocation = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $otherCustomer->id]);
        [$wrongCustomer] = $this->approvedVisit($organization, $admin, $otherCustomer, $otherLocation, 30);
        $secondLocation = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        [$wrongLocation] = $this->approvedVisit($organization, $admin, $customer, $secondLocation, 30);

        $ids = app(ApprovedVisitLaborWorkflow::class)->candidates($invoice)['eligible']
            ->map(fn (array $candidate): int => $candidate['visit']->id);
        $this->assertEquals([$eligible->id], $ids->all());
        foreach ([$unapproved, $unsubmitted, $unreviewed, $zeroLabor, $wrongCustomer, $wrongLocation] as $excluded) {
            $this->assertFalse($ids->contains($excluded->id));
        }
    }

    public function test_attach_route_enforces_invoice_authorization_organization_scope_and_context(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $invoice = $this->directInvoice($organization, $admin, $customer, $location);
        [$visit] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        [$reviewer] = $this->userWithRole('reviewer', $organization);
        $this->actingAs($reviewer)->post("/office/invoices/{$invoice->id}/visit-labor/{$visit->id}")->assertForbidden();

        [$foreignOrganization, $foreignAdmin, $foreignCustomer, $foreignLocation] = $this->scenario();
        [$foreignVisit] = $this->approvedVisit($foreignOrganization, $foreignAdmin, $foreignCustomer, $foreignLocation, 30);
        $this->actingAs($admin)->post("/office/invoices/{$invoice->id}/visit-labor/{$foreignVisit->id}")->assertNotFound();
        $this->assertTrue(AuditEvent::query()->where('event_type', 'security.cross_organization_record_denied')->get()
            ->contains(fn (AuditEvent $event): bool => ($event->metadata['record_type'] ?? null) === 'visit'
                && (int) ($event->metadata['record_id'] ?? 0) === $foreignVisit->id));

        $otherCustomer = Customer::factory()->create(['organization_id' => $organization->id]);
        $otherLocation = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $otherCustomer->id]);
        [$wrongContext] = $this->approvedVisit($organization, $admin, $otherCustomer, $otherLocation, 30);
        $this->actingAs($admin)->from("/office/invoices/{$invoice->id}")
            ->post("/office/invoices/{$invoice->id}/visit-labor/{$wrongContext->id}", ['visit_labor_context' => '1'])
            ->assertSessionHasErrors('visit');
        $this->assertDatabaseMissing('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $wrongContext->id]);
    }

    public function test_attach_rolls_back_line_and_totals_when_audit_write_fails(): void
    {
        [$organization, $admin, $customer, $location] = $this->scenario();
        $invoice = $this->directInvoice($organization, $admin, $customer, $location);
        [$visit] = $this->approvedVisit($organization, $admin, $customer, $location, 30);
        $audit = $this->mock(AuditRecorder::class);
        $audit->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit failure'));

        try {
            app(ApprovedVisitLaborWorkflow::class)->attach($invoice, $visit, $admin);
            $this->fail('The audit failure should roll back labor attachment.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('invoice_lines', ['invoice_id' => $invoice->id, 'source_visit_id' => $visit->id]);
        $this->assertSame(0, $invoice->fresh()->total_cents);
    }

    /** @return array{Organization, User, Customer, ServiceLocation} */
    private function scenario(): array
    {
        $organization = Organization::factory()->create([
            'name' => 'NewDay Tech', 'legal_name' => 'NewDay Tech LLC',
            'email' => 'billing@newdaytech.net', 'phone' => '555-0100',
            'address_line_1' => '100 Service Way', 'city' => 'Dallas',
            'state' => 'TX', 'postal_code' => '75001', 'timezone' => 'America/Chicago',
        ]);
        [$admin] = $this->userWithRole('super_admin', $organization);
        $customer = Customer::factory()->create(['organization_id' => $organization->id, 'display_name' => 'Approved Labor Customer']);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'timezone' => 'America/Chicago']);
        app(NewDayCatalogBootstrap::class)->ensureLaborServices($organization, $admin);
        $labor = CatalogService::query()->forOrganization($organization->id)->where('service_code', 'LABOR-BUS')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'default_currency' => 'USD', 'default_payment_terms' => 'due_on_receipt',
            'default_tax_rate_basis_points' => 0,
            'default_labor_catalog_service_id' => $labor->id,
            'labor_billing_increment_minutes' => 15,
            'labor_rounding_rule' => 'up',
            'minimum_billable_minutes' => 0,
        ]);

        return [$organization, $admin, $customer, $location];
    }

    private function directInvoice(Organization $organization, User $actor, Customer $customer, ServiceLocation $location): Invoice
    {
        return app(InvoiceWorkflow::class)->createDirect(
            $organization, $customer->id, $location->id, null, $actor, (string) Str::uuid(),
        );
    }

    /** @return array{Visit, Closeout, CloseoutReview} */
    private function approvedVisit(
        Organization $organization,
        User $actor,
        Customer $customer,
        ServiceLocation $location,
        int $onSiteMinutes,
        int $otherMinutes = 0,
        ?int $approvedOnSiteMinutes = null,
    ): array {
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-'.str_pad((string) (ServiceTicket::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Approved network service',
            'priority' => 'normal',
            'source' => 'phone',
            'status' => 'completed',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'status' => 'approved',
            'timezone' => $location->timezone,
            'scheduled_start_at' => Carbon::parse('2026-08-12 14:00:00', 'UTC'),
            'scheduled_end_at' => Carbon::parse('2026-08-12 16:00:00', 'UTC'),
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 2,
            'outcome' => 'resolved',
            'diagnosis' => 'Approved diagnosis',
            'work_performed' => 'Approved work',
            'submitted_token' => (string) Str::uuid(),
            'submitted_by_id' => $actor->id,
            'submitted_at' => now(),
        ]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        $start = Carbon::parse('2026-08-12 14:00:00', 'UTC');
        $onSite = VisitTimeEntry::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
            'user_id' => $actor->id, 'category' => 'on_site', 'started_at' => $start,
            'ended_at' => $start->copy()->addMinutes($onSiteMinutes), 'source' => 'manual',
        ]);
        if ($otherMinutes > 0) {
            VisitTimeEntry::query()->create([
                'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
                'user_id' => $actor->id, 'category' => 'other', 'started_at' => $start->copy()->addHours(3),
                'ended_at' => $start->copy()->addHours(3)->addMinutes($otherMinutes), 'source' => 'manual',
            ]);
        }
        VisitTimeEntry::query()->create([
            'organization_id' => $organization->id, 'visit_id' => $visit->id, 'closeout_id' => $closeout->id,
            'user_id' => $actor->id, 'category' => 'travel', 'started_at' => $start->copy()->subHour(),
            'ended_at' => $start, 'source' => 'manual',
        ]);
        $review = CloseoutReview::query()->create([
            'organization_id' => $organization->id,
            'closeout_id' => $closeout->id,
            'reviewer_id' => $actor->id,
            'decision' => 'approved',
            'self_review_override' => true,
            'decision_token' => (string) Str::uuid(),
            'decided_at' => now(),
        ]);
        if ($approvedOnSiteMinutes !== null) {
            CloseoutReviewAdjustment::query()->create([
                'organization_id' => $organization->id,
                'closeout_review_id' => $review->id,
                'type' => 'time',
                'visit_time_entry_id' => $onSite->id,
                'excluded' => false,
                'approved_minutes' => $approvedOnSiteMinutes,
                'reason' => 'Reviewer-approved duration.',
            ]);
        }

        return [$visit->fresh(), $closeout->fresh(), $review->fresh()];
    }

    /** @return array{User, Organization, OrganizationMembership} */
    private function userWithRole(string $roleKey, ?Organization $organization = null): array
    {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', $roleKey)->firstOrFail());

        return [$user, $organization, $membership];
    }
}
