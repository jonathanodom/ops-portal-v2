<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'project_number' => 'NDT-PRJ-'.now()->year.'-'.$this->faker->unique()->numberBetween(1000, 9999),
            'name' => $this->faker->sentence(3),
            'type' => 'installation_project',
            'status' => 'planning',
        ];
    }
}
