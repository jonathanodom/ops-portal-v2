<?php

namespace Tests\Unit;

use App\Domain\NewDayCatalogBootstrap;
use App\Domain\TripChargeRecommender;
use App\Models\Closeout;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationBillingSetting;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TripChargeRecommenderTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('boundaries')]
    public function test_it_uses_exact_travel_second_boundaries(int $seconds, ?string $variantCode, ?int $price): void
    {
        [$visit, $closeout] = $this->visitWithPolicy();
        $this->travelEntry($visit, $closeout, 0, $seconds);

        $recommendation = app(TripChargeRecommender::class)->recommend($visit);

        $this->assertSame($seconds, $recommendation->travelSeconds);
        $this->assertSame($variantCode, $recommendation->variant?->code);
        $this->assertSame($price, $recommendation->priceCents);
        $this->assertSame($variantCode !== null, $recommendation->isRecommended());
    }

    /** @return array<string, array{int, ?string, ?int}> */
    public static function boundaries(): array
    {
        return [
            '44:59' => [2699, null, null],
            '45:00' => [2700, 'TRIP-45-60', 4500],
            '59:59' => [3599, 'TRIP-45-60', 4500],
            '60:00' => [3600, 'TRIP-60-PLUS', 6500],
            '60:01' => [3601, 'TRIP-60-PLUS', 6500],
        ];
    }

    public function test_it_counts_only_completed_travel_and_merges_overlapping_crew_intervals(): void
    {
        [$visit, $closeout] = $this->visitWithPolicy();
        $this->travelEntry($visit, $closeout, 0, 1800);
        $this->travelEntry($visit, $closeout, 600, 2700);
        VisitTimeEntry::query()->create([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'user_id' => User::factory()->create()->id,
            'category' => 'on_site',
            'started_at' => $visit->en_route_at,
            'ended_at' => $visit->en_route_at->copy()->addHours(4),
            'source' => 'manual',
        ]);
        VisitTimeEntry::query()->create([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'user_id' => User::factory()->create()->id,
            'category' => 'travel',
            'started_at' => $visit->en_route_at,
            'ended_at' => null,
            'source' => 'timer',
        ]);

        $recommendation = app(TripChargeRecommender::class)->recommend($visit);

        $this->assertSame(2700, $recommendation->travelSeconds);
        $this->assertSame('TRIP-45-60', $recommendation->variant?->code);
    }

    public function test_it_clips_travel_to_the_en_route_window_and_excludes_drive_home_time(): void
    {
        [$visit, $closeout] = $this->visitWithPolicy();
        $visit->update(['on_site_at' => $visit->en_route_at->copy()->addMinutes(50)]);
        $this->travelEntry($visit, $closeout, -600, 4200);
        $this->travelEntry($visit, $closeout, 7200, 9000);

        $recommendation = app(TripChargeRecommender::class)->recommend($visit->fresh());

        $this->assertSame(3000, $recommendation->travelSeconds);
        $this->assertSame('TRIP-45-60', $recommendation->variant?->code);
    }

    public function test_disabled_policy_and_no_travel_return_no_recommendation(): void
    {
        [$visit] = $this->visitWithPolicy();
        $none = app(TripChargeRecommender::class)->recommend($visit);
        $this->assertSame(0, $none->travelSeconds);
        $this->assertFalse($none->isRecommended());

        OrganizationBillingSetting::query()->where('organization_id', $visit->organization_id)->update(['suggest_trip_charges' => false]);
        $visit->timeEntries()->create([
            'organization_id' => $visit->organization_id,
            'closeout_id' => $visit->current_closeout_id,
            'user_id' => User::factory()->create()->id,
            'category' => 'travel',
            'started_at' => $visit->en_route_at,
            'ended_at' => $visit->en_route_at->copy()->addHour(),
            'source' => 'manual',
        ]);
        $disabled = app(TripChargeRecommender::class)->recommend($visit);
        $this->assertSame(3600, $disabled->travelSeconds);
        $this->assertFalse($disabled->isRecommended());
    }

    public function test_auto_select_flag_is_projected_but_remains_off_by_default(): void
    {
        [$visit, $closeout] = $this->visitWithPolicy();
        $this->travelEntry($visit, $closeout, 0, 3600);
        $this->assertFalse(app(TripChargeRecommender::class)->recommend($visit)->selectedByDefault);

        OrganizationBillingSetting::query()->where('organization_id', $visit->organization_id)->update(['auto_select_trip_charges' => true]);
        $this->assertTrue(app(TripChargeRecommender::class)->recommend($visit)->selectedByDefault);
    }

    /** @return array{Visit, Closeout} */
    private function visitWithPolicy(): array
    {
        $organization = Organization::factory()->create();
        app(NewDayCatalogBootstrap::class)->ensureTripCharge($organization);
        $trip = $organization->catalogServices()->where('service_code', 'TRIP')->firstOrFail();
        OrganizationBillingSetting::query()->create([
            'organization_id' => $organization->id,
            'trip_charge_catalog_service_id' => $trip->id,
            'suggest_trip_charges' => true,
            'auto_select_trip_charges' => false,
        ]);
        $customer = Customer::factory()->create(['organization_id' => $organization->id]);
        $location = ServiceLocation::factory()->create(['organization_id' => $organization->id, 'customer_id' => $customer->id]);
        $ticket = ServiceTicket::query()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'service_location_id' => $location->id,
            'ticket_number' => 'NDT-ST-2026-TRIP',
            'title' => 'Trip recommendation',
            'priority' => 'normal',
            'source' => 'internal',
            'status' => 'open',
        ]);
        $visit = Visit::query()->create([
            'organization_id' => $organization->id,
            'service_ticket_id' => $ticket->id,
            'service_location_id' => $location->id,
            'timezone' => $location->timezone,
            'status' => 'on_site',
            'en_route_at' => Carbon::parse('2026-08-14 13:00:00', 'UTC'),
        ]);
        $closeout = Closeout::query()->create([
            'organization_id' => $organization->id,
            'visit_id' => $visit->id,
            'version' => 1,
            'status' => 'draft',
            'content_version' => 1,
        ]);
        $visit->update(['current_closeout_id' => $closeout->id]);

        return [$visit->fresh(), $closeout];
    }

    private function travelEntry(Visit $visit, Closeout $closeout, int $startOffsetSeconds, int $endOffsetSeconds): void
    {
        VisitTimeEntry::query()->create([
            'organization_id' => $visit->organization_id,
            'visit_id' => $visit->id,
            'closeout_id' => $closeout->id,
            'user_id' => User::factory()->create()->id,
            'category' => 'travel',
            'started_at' => $visit->en_route_at->copy()->addSeconds($startOffsetSeconds),
            'ended_at' => $visit->en_route_at->copy()->addSeconds($endOffsetSeconds),
            'source' => 'manual',
        ]);
    }
}
