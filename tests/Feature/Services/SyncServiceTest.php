<?php

namespace Tests\Feature\Services;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Services\RusGuard\RusGuardDatabaseService;
use App\Services\SyncService;
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

        $syncService = new SyncService($rusGuardDb);

        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['errors']);

        $accessPoint = AccessPoint::where('rusguard_access_point_id', $driverId)->first();

        $this->assertNotNull($accessPoint);
        $this->assertSame(2, $accessPoint->employees()->count());
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

        $syncService = new SyncService($rusGuardDb);
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['errors']);
        $this->assertSame(1, $result['synced']);

        $this->assertSame(1, AccessPoint::where('rusguard_access_point_id', 'point-a')->first()->employees()->count());
        $this->assertSame(1, AccessPoint::where('rusguard_access_point_id', 'point-b')->first()->employees()->count());
    }

    public function test_deactivates_local_employee_no_longer_active_in_rusguard(): void
    {
        $accessPoint = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-x']);
        $gone = Employee::factory()->create(['rusguard_uuid' => '33333333-3333-3333-3333-333333333333', 'is_active' => true]);
        $accessPoint->employees()->attach($gone->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([]);
        // The employee no longer shows up in RusGuard's active roster at all — fired/excluded.
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->once()->andReturn([]);

        $syncService = new SyncService($rusGuardDb);
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(1, $result['deactivated']);
        $this->assertFalse($gone->fresh()->is_active);
        $this->assertSame(0, $gone->accessPoints()->count());
    }

    /**
     * @param  array<int, string>  $driverIds
     */
    private function rusGuardReturningPoints(array $driverIds): RusGuardDatabaseService
    {
        $points = array_map(fn (string $id): array => [
            'driverId' => $id,
            'name' => 'Point '.$id,
            'deviceType' => 'Турникет',
            'employees' => [],
        ], $driverIds);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn($points);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->andReturn([]);

        return $rusGuardDb;
    }

    public function test_deactivates_access_point_deleted_in_rusguard(): void
    {
        $kept = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-live', 'is_active' => true]);
        $gone = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-deleted', 'is_active' => true]);

        $syncService = new SyncService($this->rusGuardReturningPoints(['point-live']));
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(1, $result['pointsDeactivated']);
        $this->assertFalse($gone->fresh()->is_active);
        $this->assertTrue($kept->fresh()->is_active);
    }

    public function test_deactivating_a_point_keeps_its_employee_links(): void
    {
        $gone = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-deleted', 'is_active' => true]);
        $employee = Employee::factory()->create(['is_active' => true]);
        $gone->employees()->attach($employee->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([[
            'driverId' => 'point-live',
            'name' => 'Point live',
            'deviceType' => 'Турникет',
            'employees' => [],
        ]]);
        // The employee is still employed — only their access point disappeared, so nothing
        // else in the sync has a reason to touch their links.
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->andReturn([$employee->rusguard_uuid]);

        $syncService = new SyncService($rusGuardDb);
        $syncService->syncAllFromRusGuard();

        // Detaching here would leave a Hikvision terminal bound to this point seeing an empty
        // roster, and its sync would then delete every person from the device.
        $this->assertSame(1, $gone->fresh()->employees()->count());
    }

    public function test_an_empty_rusguard_response_deactivates_no_points(): void
    {
        $accessPoint = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-live', 'is_active' => true]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        // An outage or failed query, not "every access point was deleted".
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([]);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->andReturn([]);

        $syncService = new SyncService($rusGuardDb);
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['pointsDeactivated']);
        $this->assertTrue($accessPoint->fresh()->is_active);
    }

    public function test_uuid_case_difference_is_not_mistaken_for_a_deleted_point(): void
    {
        // SQL Server hands back uppercase uuids while Postgres canonicalizes to lowercase.
        $accessPoint = AccessPoint::factory()->create([
            'rusguard_access_point_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'is_active' => true,
        ]);

        $syncService = new SyncService(
            $this->rusGuardReturningPoints(['AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE'])
        );
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['pointsDeactivated']);
        $this->assertTrue($accessPoint->fresh()->is_active);
    }

    public function test_leaves_active_employee_untouched(): void
    {
        $accessPoint = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-y']);
        $stillHere = Employee::factory()->create(['rusguard_uuid' => '44444444-4444-4444-4444-444444444444', 'is_active' => true]);
        $accessPoint->employees()->attach($stillHere->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointsWithEmployees')->once()->andReturn([]);
        $rusGuardDb->shouldReceive('getActiveEmployeeUuids')->once()->andReturn(['44444444-4444-4444-4444-444444444444']);

        $syncService = new SyncService($rusGuardDb);
        $result = $syncService->syncAllFromRusGuard();

        $this->assertSame(0, $result['deactivated']);
        $this->assertTrue($stillHere->fresh()->is_active);
        $this->assertSame(1, $stillHere->accessPoints()->count());
    }

    public function test_access_point_sync_writes_to_its_own_cache_key_not_the_global_one(): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, ['status' => 'running', 'emp_total' => 11925]);

        $accessPoint = AccessPoint::factory()->create(['rusguard_access_point_id' => 'point-1']);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getAccessPointDeviceType')->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeesForAccessPoint')->once()->andReturn([
            ['uuid' => '11111111-1111-1111-1111-111111111111', 'fio' => 'Фамилия Имя'],
        ]);
        $rusGuardDb->shouldReceive('getEmployeePhoto')->andReturn(null);
        $rusGuardDb->shouldReceive('getEmployeeKeys')->andReturn([]);

        $syncService = new SyncService($rusGuardDb);
        $syncService->syncEmployeesForAccessPoint($accessPoint->id);

        $scoped = Cache::get(SyncService::SYNC_STATUS_KEY.'_'.$accessPoint->id);
        $this->assertSame('done', $scoped['status']);
        $this->assertSame(1, $scoped['emp_total']);

        // The unrelated global "sync all" progress must be untouched by a per-access point sync.
        $global = Cache::get(SyncService::SYNC_STATUS_KEY);
        $this->assertSame('running', $global['status']);
        $this->assertSame(11925, $global['emp_total']);
    }
}
