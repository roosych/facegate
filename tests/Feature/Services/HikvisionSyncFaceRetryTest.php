<?php

namespace Tests\Feature\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Models\Turnstile;
use App\Services\HikvisionSyncService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HikvisionSyncFaceRetryTest extends TestCase
{
    use RefreshDatabase;

    private HikvisionTerminal $terminal;

    private Employee $employee;

    private string $photoPath = 'testing/face-retry-test.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        $absolute = storage_path('app/'.$this->photoPath);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        $image = imagecreatetruecolor(120, 160);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);

        $turnstile = Turnstile::factory()->create(['is_active' => true]);
        $this->terminal = HikvisionTerminal::factory()->create([
            'ip' => '127.0.0.1',
            'access_point_id' => $turnstile->id,
        ]);

        $this->employee = Employee::factory()->create(['emp_code' => 10, 'photo_path' => $this->photoPath]);
        $this->employee->turnstiles()->attach($turnstile->id);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);
    }

    protected function tearDown(): void
    {
        $absolute = storage_path('app/'.$this->photoPath);

        if (file_exists($absolute)) {
            unlink($absolute);
        }

        parent::tearDown();
    }

    /**
     * The terminal knows the person but permanently refuses their photo — the real
     * 'alreadyExistThisFace' case, which no retry has ever been observed to clear.
     */
    private function fakeTerminalRefusingFaces(): void
    {
        Http::fake([
            '*deviceInfo*' => Http::response(['DeviceInfo' => ['deviceName' => 'test']], 200),
            '*UserInfo/Search*' => Http::response(['UserInfoSearch' => [
                'totalMatches' => 1,
                'UserInfo' => [[
                    'employeeNo' => '10',
                    'name' => mb_substr($this->employee->full_name, 0, 32),
                    'numOfCard' => 0,
                    'numOfFace' => 0,
                ]],
            ]], 200),
            '*CardInfo/Search*' => Http::response(['CardInfoSearch' => ['totalMatches' => 0, 'CardInfo' => []]], 200),
            '*FDLib/FDSearch*' => Http::response(['totalMatches' => 0, 'MatchList' => []], 200),
            '*FDSetUp*' => Http::response(['statusCode' => 4, 'subStatusCode' => 'alreadyExistThisFace'], 400),
            '*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);
    }

    private function faceUploadAttempts(): int
    {
        $count = 0;

        Http::assertSent(function (Request $request) use (&$count) {
            if (str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDSetUp')) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    private function faceErrorCount(): int
    {
        return SyncLog::where('action', 'hikvision_face')->where('status', 'error')->count();
    }

    public function test_a_rejected_photo_is_not_retried_or_relogged_on_every_run(): void
    {
        $sync = app(HikvisionSyncService::class);

        $this->fakeTerminalRefusingFaces();
        $sync->syncEmployeesForTerminal($this->terminal);

        $this->assertSame(1, $this->faceErrorCount(), 'the first failure must be reported');
        $attemptsAfterFirstRun = $this->faceUploadAttempts();

        $this->fakeTerminalRefusingFaces();
        $sync->syncEmployeesForTerminal($this->terminal->fresh());

        $this->assertSame(0, $this->faceUploadAttempts(), 'the same photo must not be pushed again');
        $this->assertSame(1, $this->faceErrorCount(), 'and it must not be logged again');
        $this->assertGreaterThan(0, $attemptsAfterFirstRun);
    }

    public function test_a_changed_photo_is_tried_again(): void
    {
        $sync = app(HikvisionSyncService::class);

        $this->fakeTerminalRefusingFaces();
        $sync->syncEmployeesForTerminal($this->terminal);
        $this->assertSame(1, $this->faceErrorCount());

        // A new photo is the one thing that can change the outcome, so the skip must lift.
        $image = imagecreatetruecolor(160, 200);
        imagejpeg($image, storage_path('app/'.$this->photoPath), 70);
        imagedestroy($image);

        $this->fakeTerminalRefusingFaces();
        $sync->syncEmployeesForTerminal($this->terminal->fresh());

        $this->assertGreaterThan(0, $this->faceUploadAttempts(), 'a new photo deserves a fresh attempt');
        $this->assertSame(2, $this->faceErrorCount());
    }

    public function test_the_employee_is_still_counted_as_missing_a_face_while_skipped(): void
    {
        $sync = app(HikvisionSyncService::class);

        $this->fakeTerminalRefusingFaces();
        $sync->syncEmployeesForTerminal($this->terminal);

        $this->fakeTerminalRefusingFaces();
        $results = $sync->syncEmployeesForTerminal($this->terminal->fresh());

        // Suppressing the noise must not make the summary claim the face is fine.
        $this->assertSame(0, $results['faces']);
        $this->assertSame(1, $results['synced']);

        $stats = $this->terminal->fresh()->sync_stats;
        $this->assertArrayHasKey('10', $stats['face_problems']);
        $this->assertCount(1, $stats['faces_failed']);
    }
}
