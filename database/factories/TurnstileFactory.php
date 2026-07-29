<?php

namespace Database\Factories;

use App\Models\Turnstile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Turnstile>
 */
class TurnstileFactory extends Factory
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
            'rusguard_access_point_id' => $this->faker->uuid(),
            'rusguard_access_point_name' => $this->faker->words(2, true),
            'is_active' => true,
        ];
    }
}
