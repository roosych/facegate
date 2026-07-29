<?php

namespace Database\Factories;

use App\Models\HikvisionTerminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HikvisionTerminal>
 */
class HikvisionTerminalFactory extends Factory
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
            'ip' => $this->faker->localIpv4(),
            'port' => 80,
            'username' => 'admin',
            'password' => 'secret',
            'protocol' => 'http',
            'is_active' => true,
        ];
    }

    /** Alcohol detection module enabled on this terminal. */
    public function alcoholEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'alcohol_params' => ['enabled' => true],
        ]);
    }
}
