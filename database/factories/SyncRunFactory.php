<?php

namespace Database\Factories;

use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-7 days');
        $durationMs = $this->faker->numberBetween(5_000, 120_000);

        return [
            'kind' => SyncRun::KIND_RUSGUARD,
            'triggered_by' => SyncRun::TRIGGER_SCHEDULE,
            'status' => SyncRun::STATUS_SUCCESS,
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->modify("+{$durationMs} milliseconds"),
            'duration_ms' => $durationMs,
            'stats' => ['points' => 49, 'synced' => 1005, 'errors' => 0],
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRun::STATUS_RUNNING,
            'finished_at' => null,
            'duration_ms' => null,
            'stats' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRun::STATUS_FAILED,
            'stats' => [],
            'message' => 'Terminal is offline or unreachable',
        ]);
    }
}
