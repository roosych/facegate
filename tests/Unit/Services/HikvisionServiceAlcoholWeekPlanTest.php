<?php

namespace Tests\Unit\Services;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionServiceAlcoholWeekPlanTest extends TestCase
{
    public function test_returns_empty_array_when_every_day_is_disabled(): void
    {
        Http::fake(['*' => Http::response([
            'enabled' => true,
            'weekPlanCfg' => [
                ['dayOfWeek' => 1, 'timeRange' => [['startTime' => '00:00', 'endTime' => '24:00', 'alcoholDetEnabled' => false]]],
                ['dayOfWeek' => 2, 'timeRange' => [['startTime' => '00:00', 'endTime' => '24:00', 'alcoholDetEnabled' => false]]],
            ],
        ], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $plan = $service->getAlcoholWeekPlan();

        $this->assertNotNull($plan);
        $this->assertSame([], $plan);
    }

    public function test_returns_null_on_failed_request(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->assertNull($service->getAlcoholWeekPlan());
    }

    public function test_returns_enabled_days_with_periods(): void
    {
        Http::fake(['*' => Http::response([
            'enabled' => true,
            'weekPlanCfg' => [
                ['dayOfWeek' => 1, 'timeRange' => [['startTime' => '08:00', 'endTime' => '18:00', 'alcoholDetEnabled' => true]]],
                ['dayOfWeek' => 7, 'timeRange' => [['startTime' => '00:00', 'endTime' => '24:00', 'alcoholDetEnabled' => false]]],
            ],
        ], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $plan = $service->getAlcoholWeekPlan();

        $this->assertArrayHasKey('monday', $plan);
        $this->assertArrayNotHasKey('sunday', $plan);
    }
}
