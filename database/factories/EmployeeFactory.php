<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rusguard_uuid' => $this->faker->uuid(),
            'emp_code' => $this->faker->unique()->numberBetween(1, 100000),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'is_active' => true,
        ];
    }
}
