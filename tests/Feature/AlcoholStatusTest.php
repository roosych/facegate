<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\User;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AlcoholStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_required_employees_with_terminal_and_last_pass(): void
    {
        $employee = Employee::factory()->create(['first_name' => 'Davyd', 'last_name' => 'Ahaiev']);
        $accessPoint = AccessPoint::factory()->create();
        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $accessPoint->id, 'name' => 'Post 1']);
        $employee->accessPoints()->attach($accessPoint->id);

        AccessEvent::factory()->create([
            'employee_id' => $employee->id,
            'hikvision_terminal_id' => $terminal->id,
            'access_point_id' => $accessPoint->id,
            'event_time' => now()->subMinutes(10),
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]]],
        ]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')
            ->once()
            ->andReturn([$employee->rusguard_uuid => true]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->get(route('alcohol.index'));

        $response->assertOk();
        $response->assertSee('Ahaiev Davyd');
        $response->assertSee('Post 1');
    }

    public function test_shows_empty_state_when_nobody_required(): void
    {
        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->get(route('alcohol.index'));

        $response->assertOk();
        $response->assertSee('No employees currently require alcohol testing.');
    }
}
