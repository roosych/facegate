<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\EmployeeKey;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Services\HikvisionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionNameDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_drifted_name_that_does_not_stick_is_logged_as_an_error_and_forces_a_face_reupload(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $point = AccessPoint::factory()->create();
        $terminal->update(['access_point_id' => $point->id]);

        $employee = Employee::factory()->create([
            'emp_code' => 42,
            'first_name' => 'Correct',
            'last_name' => 'Name',
            'middle_name' => '',
            'photo_path' => 'photos/none.jpg',
            'is_active' => true,
        ]);
        EmployeeKey::create(['employee_id' => $employee->id, 'type' => 'card', 'value' => '111']);
        $point->employees()->attach($employee->id);

        $wrongName = 'Someone Else Entirely';
        $faceUploadAttempted = false;

        Http::fake(function ($request) use ($wrongName, &$faceUploadAttempted) {
            $url = $request->url();
            $body = $request->data();

            return match (true) {
                str_contains($url, '/System/deviceInfo') => Http::response(['DeviceInfo' => []], 200),

                str_contains($url, '/UserInfo/Search') => Http::response([
                    'UserInfoSearch' => [
                        'responseStatusStrg' => 'OK',
                        'totalMatches' => 1,
                        'UserInfo' => [[
                            'employeeNo' => '42',
                            'name' => $wrongName,        // never changes → correction "doesn't stick"
                            'faceURL' => 'http://x/face.jpg',
                        ]],
                    ],
                ], 200),

                str_contains($url, '/CardInfo/Search') => Http::response([
                    'CardInfoSearch' => ['totalMatches' => 1, 'CardInfo' => [['employeeNo' => '42', 'cardNo' => '0000000111']]],
                ], 200),

                str_contains($url, '/FDLib/FDSearch') => Http::response([
                    'totalMatches' => 1, 'MatchList' => [['FPID' => '42']],
                ], 200),

                str_contains($url, '/FDLib/FDSetUp') || str_contains($url, '/FDLib/FDModify') => tap(
                    Http::response(['statusCode' => 1], 200),
                    function () use (&$faceUploadAttempted) {
                        $faceUploadAttempted = true;
                    }
                ),

                default => Http::response(['statusCode' => 1], 200),
            };
        });

        // photo file the uploader will read (uploadFace bails early if the file is absent)
        @mkdir(storage_path('app/photos'), 0777, true);
        file_put_contents(storage_path('app/photos/none.jpg'), 'not-a-real-jpeg');

        try {
            app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);
        } finally {
            @unlink(storage_path('app/photos/none.jpg'));
        }

        $this->assertTrue(
            SyncLog::where('action', 'hikvision_add')->where('status', 'error')
                ->where('message', 'like', '%still wrong after re-push%')->exists(),
            'expected a hikvision_add error when the name correction did not stick'
        );

        $this->assertTrue($faceUploadAttempted, 'a drifted person record should force a face re-upload');
    }
}
