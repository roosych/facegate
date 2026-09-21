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

class HikvisionCardFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: HikvisionTerminal, 1: Employee}
     */
    private function terminalWithEmployeeHavingNewCard(): array
    {
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $point = AccessPoint::factory()->create();
        $terminal->update(['access_point_id' => $point->id]);

        $employee = Employee::factory()->create([
            'emp_code' => 42,
            'photo_path' => null,
            'is_active' => true,
        ]);
        EmployeeKey::create(['employee_id' => $employee->id, 'type' => 'card', 'value' => '222']);
        $point->employees()->attach($employee->id);

        return [$terminal, $employee];
    }

    private function fakeTerminal(Employee $employee, int $cardSetUpStatus): void
    {
        Http::fake(function ($request) use ($employee, $cardSetUpStatus) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/System/deviceInfo') => Http::response(['DeviceInfo' => []], 200),

                str_contains($url, '/UserInfo/Search') => Http::response([
                    'UserInfoSearch' => [
                        'responseStatusStrg' => 'OK',
                        'totalMatches' => 1,
                        'UserInfo' => [['employeeNo' => '42', 'name' => $employee->full_name]],
                    ],
                ], 200),

                str_contains($url, '/CardInfo/Search') => Http::response([
                    'CardInfoSearch' => ['totalMatches' => 1, 'CardInfo' => [['employeeNo' => '42', 'cardNo' => '0000000111']]],
                ], 200),

                str_contains($url, '/CardInfo/SetUp') => Http::response(
                    ['statusCode' => 4, 'statusString' => 'Invalid Operation', 'subStatusCode' => 'invalidOperation'],
                    $cardSetUpStatus
                ),

                str_contains($url, '/FDLib/FDSearch') => Http::response(['totalMatches' => 0, 'MatchList' => []], 200),

                default => Http::response(['statusCode' => 1], 200),
            };
        });
    }

    public function test_a_rejected_card_write_is_counted_in_the_run_errors_and_logged(): void
    {
        [$terminal, $employee] = $this->terminalWithEmployeeHavingNewCard();
        $this->fakeTerminal($employee, 400);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertSame(1, $results['errors']);
        $this->assertSame(0, $results['cards']);
        $this->assertSame(1, SyncLog::where('action', 'hikvision_card')->where('status', 'error')->count());
        $this->assertSame(1, $terminal->fresh()->sync_stats['cards_not_added']);
    }

    public function test_an_accepted_card_write_adds_no_errors(): void
    {
        [$terminal, $employee] = $this->terminalWithEmployeeHavingNewCard();
        $this->fakeTerminal($employee, 200);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertSame(0, $results['errors']);
        $this->assertSame(1, $results['cards']);
        $this->assertSame(0, SyncLog::where('action', 'hikvision_card')->where('status', 'error')->count());
    }

    public function test_an_employee_without_card_keys_is_not_counted_as_a_run_error(): void
    {
        [$terminal, $employee] = $this->terminalWithEmployeeHavingNewCard();
        $employee->keys()->delete();
        $this->fakeTerminal($employee, 200);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertSame(0, $results['errors']);
        $this->assertSame(0, $results['cards']);
    }
}
