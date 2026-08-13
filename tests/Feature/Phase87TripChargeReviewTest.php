<?php

namespace Tests\Feature;

use App\Domain\NewDayCatalogBootstrap;
use App\Models\AuditEvent;
use App\Models\Closeout;
use App\Models\CloseoutReview;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase87TripChargeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_review_shows_an_unselected_accessible_trip_charge_recommendation_by_default(): void
    {
        [$reviewer, $visit, $closeout] = $this->submittedVisitWithTravel(52 * 60);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('Recorded en-route travel:')
            ->assertSee('52 minutes')
            ->assertSee('Add Trip / Dispatch Charge')
            ->assertSee('45–60 Minute Travel')
            ->assertSee('$45.00')
            ->assertSee('id="trip_charge_selected"', false)
            ->assertDontSee('id="trip_charge_selected" type="checkbox" name="trip_charge_selected" value="1" checked', false);

        $this->assertSame('pending_closeout', $visit->status);
    }

    public function test_approval_snapshots_the_server_recomputed_trip_charge_and_actor(): void
    {
        [$reviewer, $visit, $closeout] = $this->submittedVisitWithTravel(60 * 60);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", [
            'decision_token' => (string) Str::uuid(),
            'trip_charge_selected' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $review = CloseoutReview::query()->where('closeout_id', $closeout->id)->firstOrFail();
        $charge = $review->tripCharge()->firstOrFail();
        $this->assertSame($visit->id, $charge->visit_id);
        $this->assertSame(3600, $charge->recorded_travel_seconds);
        $this->assertSame('TRIP:TRIP-60-PLUS', $charge->catalog_code_snapshot);
        $this->assertSame(6500, $charge->catalog_unit_price_cents);
        $this->assertSame($reviewer->id, $charge->selected_by_id);
        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $visit->organization_id,
            'actor_id' => $reviewer->id,
            'event_type' => 'closeout.trip_charge_selected',
        ]);
    }

    public function test_manual_deselection_records_no_charge_and_a_safe_waiver_event(): void
    {
        [$reviewer, $visit, $closeout] = $this->submittedVisitWithTravel(45 * 60, true);

        $this->actingAs($reviewer)->get("/office/closeout-reviews/{$closeout->id}")
            ->assertOk()
            ->assertSee('id="trip_charge_selected" type="checkbox" name="trip_charge_selected" value="1" checked', false);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", [
            'decision_token' => (string) Str::uuid(),
            'trip_charge_selected' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('closeout_review_trip_charges', 0);
        $event = AuditEvent::query()->where('event_type', 'closeout.trip_charge_waived')->firstOrFail();
        $this->assertSame($visit->id, $event->metadata['visit_id']);
        $this->assertSame(2700, $event->metadata['recorded_travel_seconds']);
        $this->assertArrayNotHasKey('reason', $event->metadata);
    }

    public function test_forged_selection_without_an_eligible_recommendation_rolls_back_review(): void
    {
        [$reviewer, , $closeout] = $this->submittedVisitWithTravel(44 * 60 + 59);

        $this->actingAs($reviewer)->post("/office/closeout-reviews/{$closeout->id}/approve", [
            'decision_token' => (string) Str::uuid(),
            'trip_charge_selected' => 1,
        ])->assertSessionHasErrors('trip_charge_selected');

        $this->assertDatabaseCount('closeout_reviews', 0);
        $this->assertDatabaseCount('closeout_review_trip_charges', 0);
    }

    /** @return array{User, Visit, Closeout} */
    private function submittedVisitWithTravel(int $seconds, bool $autoSelect = false): array
    {
        $organization = Organization::factory()->create(['timezone' => 'America/Chicago']);
        app(NewDayCatalogBootstrap::class)->ensureTripCharge($organization);
        $trip = $organization->catalogServices()->where('service_code', 'TRIP')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'trip_charge_catalog_service_id' => $trip->id,
            'suggest_trip_charges' => true,
            'auto_select_trip_charges' => $autoSelect,
        ]);
        $reviewer = User::factory()->create(['status' => 'active']);
        $membership = OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $reviewer->id,
            'status' => 'active',
        ]);
        $membership->roles()->attach(Role::query()->where('key', 'reviewer')->firstOrFail());
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
            'ticket_number' => 'NDT-ST-2026-TRIP-'.$organization->id,
            'title' => 'Trip charge review',
            'priority' => 'normal',
            'source' => 'internal',
            'status' => 'open',
        ]);
        $start = Carbon::parse('2026-08-13 14:00:00', 'UTC');
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'timezone' => $location->timezone,
            'status' => 'pending_closeout',
            'en_route_at' => $start,
            'on_site_at' => $start->copy()->addSeconds($seconds),
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'submitted',
            'content_version' => 2,
            'outcome' => 'resolved',
            'diagnosis' => 'Network fault',
            'work_performed' => 'Restored service',
            'ack_unavailable_category' => 'remote_service',
            'ack_unavailable_detail' => 'Remote work',
            'no_photo_category' => 'not_applicable',
            'no_photo_detail' => 'No visual evidence applicable',
            'submitted_token' => (string) Str::uuid(),
            'submitted_by_id' => User::factory()->create()->id,
            'submitted_at' => now(),
        ]);
        $visit->update(['current_closeout_id' => $closeout->id]);
        VisitTimeEntry::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'user_id' => User::factory()->create()->id,
            'category' => 'travel',
            'started_at' => $start,
            'ended_at' => $start->copy()->addSeconds($seconds),
            'source' => 'timer',
        ]);

        return [$reviewer, $visit->fresh(), $closeout];
    }
}
