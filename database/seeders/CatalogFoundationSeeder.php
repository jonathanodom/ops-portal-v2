<?php

namespace Database\Seeders;

use App\Domain\CatalogDefaults;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class CatalogFoundationSeeder extends Seeder
{
    public function run(CatalogDefaults $defaults): void
    {
        Organization::query()->each(fn (Organization $organization) => $defaults->ensureFor($organization));
    }
}
