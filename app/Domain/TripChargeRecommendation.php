<?php

namespace App\Domain;

use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;

final readonly class TripChargeRecommendation
{
    public function __construct(
        public int $travelSeconds,
        public ?CatalogService $service = null,
        public ?CatalogServiceVariant $variant = null,
        public ?int $priceCents = null,
        public bool $selectedByDefault = false,
    ) {}

    public function isRecommended(): bool
    {
        return $this->service !== null && $this->variant !== null && $this->priceCents !== null;
    }
}
