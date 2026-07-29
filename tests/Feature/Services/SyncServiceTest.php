<?php

namespace Tests\Feature\Services;

use App\Models\Employee;
use App\Models\Turnstile;
use App\Services\RusGuard\RusGuardDatabaseService;
use App\Services\SyncService;
use App\Services\ZKBioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_employees_when_rusguard_returns_duplicate_driver_id_entries(): void
    {
        $driverId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([
            [
                'driverId' => $driverId,
                'name' => 'Duplicated Point',
                'deviceType' => 'Дверь',
                'employees' => [['uuid' => '11111111-1111-1111-1111-111111111111', 'fio' => 'Фамилия Имя']],
            ],
            [
                'driverId' => $driverId,
                'name' => 'Duplicated Point',
                'deviceType' => 'Дверь',
                'employees' => [['uuid' => '22222222-2222-2222-2222-222222222222', 'fio' => 'Другой Сотрудник']],
            ],
        ]);
        $rusGuardDb->shouldReceive('getEmployeePhoto')->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeeKeys')->andReturn([]);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->andReturn([
            '11111111-1111-1111-1111-111111111111',
            '22222222-2222-2222-2222-222222222222',
        ]);

        $syncService = new SyncService($rusGuardDb, app(ZKBioService::class));

        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['errors']);

        $turnstile = Turnstile::where('rusguard_access_point_id', $driverId)->first();

        $this->assertNotNull($turnstile);
        $this->assertSame(2, $turnstile->employees()->count());
    }

    public function test_employee_seen_on_multiple_access_points_is_only_fetched_once(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([
            [
                'driverId' => 'point-a',
                'name' => 'Point A',
                'deviceType' => 'Дверь',
                'employees' => [['uuid' => $uuid, 'fio' => 'Фамилия Имя']],
            ],
            [
                'driverId' => 'point-b',
                'name' => 'Point B',
                'deviceType' => 'Дверь',
                'employees' => [['uuid' => $uuid, 'fio' => 'Фамилия Имя']],
            ],
        ]);
        // The uuid appears on two points — photo/keys must only be fetched once per sync run.
        $rusGuardDb->shouldReceive('getEmployeePhoto')->once()->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeeKeys')->once()->andReturn([]);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->andReturn([$uuid]);

        $syncService = new SyncService($rusGuardDb, app(ZKBioService::class));
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['synced']);

        $this->assertSame(1, Turnstile::where('rusguard_access_point_id', 'point-a')->first()->employees()->count());
        $this->assertSame(1, Turnstile::where('rusguard_access_point_id', 'point-b')->first()->employees()->count());
    }

    public function test_deactivates_local_employee_no_longer_active_in_rusguard(): void
    {
        $turnstile = Turnstile::factory()->create(['rusguard_access_point_id' => 'point-x']);
        $gone = Employee::factory()->create(['rusguard_uuid' => '33333333-3333-3333-3333-333333333333', 'is_active' => true]);
        $turnstile->employees()->attach($gone->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([]);
        // The employee no longer shows up in RusGuard's active roster at all — fired/excluded.
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->once()->andReturn([]);

        $syncService = new SyncService($rusGuardDb, app(ZKBioService::class));
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(1, $result['deactivated']);
        $this->assertFalse($gone->fresh()->is_active);
        $this->assertSame(0, $gone->turnstiles()->count());
    }

    public function test_leaves_active_employee_untouched(): void
    {
        $turnstile = Turnstile::factory()->create(['rusguard_access_point_id' => 'point-y']);
        $stillHere = Employee::factory()->create(['rusguard_uuid' => '44444444-4444-4444-4444-444444444444', 'is_active' => true]);
        $turnstile->employees()->attach($stillHere->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([]);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->once()->andReturn(['44444444-4444-4444-4444-444444444444']);

        $syncService = new SyncService($rusGuardDb, app(ZKBioService::class));
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['deactivated']);
        $this->assertTrue($stillHere->fresh()->is_active);
        $this->assertSame(1, $stillHere->turnstiles()->count());
    }

    public function test_turnstile_sync_writes_to_its_own_cache_key_not_the_global_one(): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, ['status' => 'running', 'emp_total' => 11925]);

        $turnstile = Turnstile::factory()->create(['rusguard_access_point_id' => 'point-1']);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointDeviceType')->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeesForAccessPoint')->once()->andReturn([
            ['uuid' => '11111111-1111-1111-1111-111111111111', 'fio' => 'Фамилия Имя'],
        ]);
        $rusGuardDb->shouldReceive('getEmployeePhoto')->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeeKeys')->andReturn([]);

        $syncService = new SyncService($rusGuardDb, app(ZKBioService::class));
        $syncService->syncEmployeesForTurnstile($turnstile->id);

        $scoped = Cache::get(SyncService::SYNC_STATUS_KEY.'_'.$turnstile->id);
        $this->assertSame('done', $scoped['status']);
        $this->assertSame(1, $scoped['emp_total']);

        // The unrelated global "sync all" progress must be untouched by a per-turnstile sync.
        $global = Cache::get(SyncService::SYNC_STATUS_KEY);
        $this->assertSame('running', $global['status']);
        $this->assertSame(11925, $global['emp_total']);
    }
}
