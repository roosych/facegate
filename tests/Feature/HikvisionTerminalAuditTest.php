<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\EmployeeKey;
use App\Models\HikvisionTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionTerminalAuditTest extends TestCase
{
    use RefreshDatabase;

    private function linkedTerminal(string $terminalName): HikvisionTerminal
    {
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $point = AccessPoint::factory()->create();
        $terminal->update(['access_point_id' => $point->id]);

        $employee = Employee::factory()->create([
            'emp_code' => 42, 'first_name' => 'Real', 'last_name' => 'Person', 'middle_name' => '',
            'photo_path' => null, 'is_active' => true,
        ]);
        EmployeeKey::create(['employee_id' => $employee->id, 'type' => 'card', 'value' => '111']);
        $point->employees()->attach($employee->id);

        $this->fakeTerminal($terminalName);

        return $terminal;
    }

    private function fakeTerminal(string $nameOnDevice): void
    {
        Http::fake(function ($request) use ($nameOnDevice) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/System/deviceInfo') => Http::response(['DeviceInfo' => []], 200),
                str_contains($url, '/UserInfo/Search') => Http::response([
                    'UserInfoSearch' => ['totalMatches' => 1, 'UserInfo' => [[
                        'employeeNo' => '42', 'name' => $nameOnDevice, 'faceURL' => '',
                    ]]],
                ], 200),
                str_contains($url, '/CardInfo/Search') => Http::response([
                    'CardInfoSearch' => ['totalMatches' => 1, 'CardInfo' => [['employeeNo' => '42', 'cardNo' => '0000000111']]],
                ], 200),
                str_contains($url, '/FDLib/FDSearch') => Http::response(['totalMatches' => 0, 'MatchList' => []], 200),
                default => Http::response(['statusCode' => 1], 200),
            };
        });
    }

    public function test_passes_when_terminal_matches_db(): void
    {
        $terminal = $this->linkedTerminal('Person Real');

        $this->artisan('hikvision:audit-terminal', ['terminal' => $terminal->id])
            ->expectsOutputToContain('no drift')
            ->assertSuccessful();
    }

    public function test_fails_and_reports_when_a_name_has_drifted(): void
    {
        $terminal = $this->linkedTerminal('Wrong Colleague');

        $this->artisan('hikvision:audit-terminal', ['terminal' => $terminal->id])
            ->expectsOutputToContain('drifted')
            ->assertFailed();
    }
}
