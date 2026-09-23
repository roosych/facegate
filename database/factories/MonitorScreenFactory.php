<?php

namespace Database\Factories;

use App\Models\MonitorScreen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitorScreen>
 */
class MonitorScreenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
        ];
    }
}
