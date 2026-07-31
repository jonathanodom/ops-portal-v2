<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        $customer = Customer::factory()->create();
        $phone = fake()->phoneNumber();

        return [
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'name' => fake()->name(),
            'role' => fake()->jobTitle(),
            'phone' => $phone,
            'phone_normalized' => preg_replace('/\D+/', '', $phone),
            'email' => fake()->safeEmail(),
            'is_preferred' => false,
            'active' => true,
        ];
    }
}
