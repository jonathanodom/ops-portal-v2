<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\ServiceLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceLocation> */
class ServiceLocationFactory extends Factory
{
    public function definition(): array
    {
        $customer = Customer::factory()->create();

        return [
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'name' => 'Primary service location',
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'state' => 'TX',
            'postal_code' => fake()->postcode(),
            'timezone' => 'America/Chicago',
            'access_instructions' => null,
            'site_notes' => null,
            'is_primary' => true,
            'active' => true,
        ];
    }
}
