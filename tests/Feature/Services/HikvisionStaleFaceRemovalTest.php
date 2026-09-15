<?php

namespace Tests\Feature\Services;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Services\HikvisionSyncService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HikvisionStaleFaceRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_face_still_on_the_terminal_is_deleted_once_the_local_photo_is_gone(): void
    {
        $accessPoint = AccessPoint::factory()->create(['is_active' => true]);
        $terminal = HikvisionTerminal::factory()->create([
            'ip' => '127.0.0.1',
            'access_point_id' => $accessPoint->id,
        ]);

        // photo_path is null, as it would be after SyncService clears it because RusGuard no
        // longer has a photo for this employee.
        $employee = Employee::factory()->create([
            'emp_code' => 77,
            'first_name' => 'Anna',
            'last_name' => 'Noface',
            'photo_path' => null,
        ]);
        $employee->accessPoints()->attach($accessPoint->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        Http::fake([
            '*deviceInfo*' => Http::response(['DeviceInfo' => ['deviceName' => 'test']], 200),
            '*UserInfo/Search*' => Http::response(['UserInfoSearch' => [
                'totalMatches' => 1,
                'UserInfo' => [[
                    'employeeNo' => '77',
                    'name' => mb_substr($employee->full_name, 0, 32),
                    'faceURL' => 'http://x/face.jpg',
                ]],
            ]], 200),
            '*CardInfo/Search*' => Http::response(['CardInfoSearch' => ['totalMatches' => 0, 'CardInfo' => []]], 200),
            // The terminal still has a face enrolled from before the photo was deleted.
            '*FDLib/FDSearch*' => Http::response(['totalMatches' => 1, 'MatchList' => [['FPID' => '77']]], 200),
            '*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDSearch/Delete')
            && str_contains($request->body(), '"FPID":["77"]'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDSetUp'));

        $this->assertTrue(
            SyncLog::where('action', 'hikvision_face')->where('status', 'success')
                ->where('message', 'like', '%Stale face removed%')->exists()
        );

        $this->assertSame(0, $results['faces']);
        $this->assertSame(0, $results['guestsSkipped']);
    }
}
