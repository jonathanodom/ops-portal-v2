<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        $phone = fake()->phoneNumber();

        return [
            'organization_id' => Organization::factory(),
            'type' => 'business',
            'display_name' => fake()->company(),
            'legal_name' => null,
            'phone' => $phone,
            'phone_normalized' => preg_replace('/\D+/', '', $phone),
            'email' => fake()->companyEmail(),
            'status' => 'active',
            'notes' => null,
        ];
    }
}
