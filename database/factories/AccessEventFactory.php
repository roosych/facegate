<?php

namespace Database\Factories;

use App\Models\AccessEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessEvent>
 */
class AccessEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_time' => now(),
            'verify_type' => 'faceOrCard',
            'raw_data' => [],
        ];
    }
}
