<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\User;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AccessPointCheckPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_rusguard_uuid_regardless_of_case(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';
        AccessPoint::factory()->create(['rusguard_access_point_id' => $uuid, 'name' => 'Point A']);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        // SQL Server's CONVERT(varchar(36), ...) returns uppercase.
        $rusGuardDb->shouldReceive('getAccessPoints')->once()->andReturn([
            ['driverId' => strtoupper($uuid), 'name' => 'Point A', 'type' => 'Дверь', 'employeeCount' => 3],
        ]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->getJson(route('access-points.check-points'));

        $response->assertOk();
        $response->assertJsonCount(0, 'missing');
        $response->assertJsonCount(0, 'extra');
    }

    public function test_reports_a_point_with_no_local_match_as_missing(): void
    {
        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPoints')->once()->andReturn([
            ['driverId' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE', 'name' => 'New Door', 'type' => 'Дверь', 'employeeCount' => 0],
        ]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->getJson(route('access-points.check-points'));

        $response->assertOk();
        $response->assertJsonCount(1, 'missing');
        $response->assertJsonPath('missing.0.name', 'New Door');
    }

    public function test_deactivated_orphaned_accessPoint_is_not_reported_as_extra(): void
    {
        AccessPoint::factory()->create([
            'rusguard_access_point_id' => '99999999-8888-7777-6666-555555555555',
            'is_active' => false,
        ]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPoints')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->getJson(route('access-points.check-points'));

        $response->assertOk();
        $response->assertJsonCount(0, 'extra');
    }
}
