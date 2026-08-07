<?php

namespace Tests\Feature\Models;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAlcoholTest extends TestCase
{
    use RefreshDatabase;

    public function test_skip_is_not_active_when_null(): void
    {
        $employee = Employee::factory()->make(['alcohol_skip_until' => null]);

        $this->assertFalse($employee->isAlcoholSkipActive());
    }

    public function test_skip_is_active_when_in_the_future(): void
    {
        $employee = Employee::factory()->make(['alcohol_skip_until' => now()->addMinutes(30)]);

        $this->assertTrue($employee->isAlcoholSkipActive());
    }

    public function test_skip_is_not_active_when_in_the_past(): void
    {
        $employee = Employee::factory()->make(['alcohol_skip_until' => now()->subMinutes(1)]);

        $this->assertFalse($employee->isAlcoholSkipActive());
    }

    public function test_alcohol_enabled_terminals_only_includes_enabled_ones(): void
    {
        $employee = Employee::factory()->create();

        $enabledAccessPoint = AccessPoint::factory()->create();
        $disabledAccessPoint = AccessPoint::factory()->create();
        $noTerminalAccessPoint = AccessPoint::factory()->create();

        $enabledTerminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $enabledAccessPoint->id]);
        HikvisionTerminal::factory()->create(['access_point_id' => $disabledAccessPoint->id, 'alcohol_params' => ['enabled' => false]]);

        $employee->accessPoints()->attach([$enabledAccessPoint->id, $disabledAccessPoint->id, $noTerminalAccessPoint->id]);

        $terminals = $employee->alcoholEnabledTerminals();

        $this->assertCount(1, $terminals);
        $this->assertSame($enabledTerminal->id, $terminals->first()->id);
    }
}
