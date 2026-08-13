<?php

namespace App\Domain;

use App\Models\CatalogService;
use App\Models\OrganizationBillingSetting;
use App\Models\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TripChargeRecommender
{
    public function __construct(private readonly CatalogPricingResolver $pricing) {}

    public function recommend(Visit $visit): TripChargeRecommendation
    {
        $travelSeconds = $this->enRouteTravelSeconds($visit);
        $settings = OrganizationBillingSetting::query()
            ->where('organization_id', $visit->organization_id)
            ->first();
        if (! $settings?->suggest_trip_charges || ! $settings->trip_charge_catalog_service_id) {
            return new TripChargeRecommendation($travelSeconds);
        }

        $service = CatalogService::query()
            ->forOrganization($visit->organization_id)
            ->where('active', true)
            ->where('pricing_model', 'variant')
            ->with(['variants' => fn ($query) => $query->where('active', true)])
            ->find($settings->trip_charge_catalog_service_id);
        if (! $service) {
            throw ValidationException::withMessages([
                'trip_charge' => 'The configured Trip / Dispatch Catalog Service is unavailable to this Organization.',
            ]);
        }

        $variantCode = match (true) {
            $travelSeconds >= 3600 => 'TRIP-60-PLUS',
            $travelSeconds >= 2700 => 'TRIP-45-60',
            default => null,
        };
        if ($variantCode === null) {
            return new TripChargeRecommendation($travelSeconds);
        }
        $variant = $service->variants->firstWhere('code', $variantCode);
        if (! $variant) {
            throw ValidationException::withMessages([
                'trip_charge' => "The configured Trip / Dispatch Catalog Service is missing the {$variantCode} tier.",
            ]);
        }

        return new TripChargeRecommendation(
            travelSeconds: $travelSeconds,
            service: $service,
            variant: $variant,
            priceCents: $this->pricing->servicePrice($service, $variant),
            selectedByDefault: (bool) $settings->auto_select_trip_charges,
        );
    }

    public function enRouteTravelSeconds(Visit $visit): int
    {
        $entries = $visit->timeEntries()
            ->where('category', 'travel')
            ->whereNotNull('ended_at')
            ->orderBy('started_at')
            ->get(['started_at', 'ended_at']);
        if ($entries->isEmpty()) {
            return 0;
        }

        $windowStart = $visit->en_route_at;
        $windowEnd = $visit->on_site_at;
        $intervals = $entries->map(function ($entry) use ($windowStart, $windowEnd): ?array {
            $start = $entry->started_at;
            $end = $entry->ended_at;
            if ($windowStart && $start->lt($windowStart)) {
                $start = $windowStart;
            }
            if ($windowEnd && $end->gt($windowEnd)) {
                $end = $windowEnd;
            }
            if ($end->lte($start)) {
                return null;
            }

            return [$start, $end];
        })->filter()->values();

        return $this->mergedSeconds($intervals);
    }

    /** @param Collection<int, array{CarbonInterface, CarbonInterface}> $intervals */
    private function mergedSeconds(Collection $intervals): int
    {
        $total = 0;
        $currentStart = null;
        $currentEnd = null;
        foreach ($intervals as [$start, $end]) {
            if ($currentStart === null) {
                $currentStart = $start;
                $currentEnd = $end;

                continue;
            }
            if ($start->lte($currentEnd)) {
                if ($end->gt($currentEnd)) {
                    $currentEnd = $end;
                }

                continue;
            }
            $total += (int) $currentStart->diffInSeconds($currentEnd);
            $currentStart = $start;
            $currentEnd = $end;
        }
        if ($currentStart !== null) {
            $total += (int) $currentStart->diffInSeconds($currentEnd);
        }

        return $total;
    }
}
