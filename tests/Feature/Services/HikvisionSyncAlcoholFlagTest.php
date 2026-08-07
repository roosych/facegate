<?php

namespace Tests\Feature\Services;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use App\Services\HikvisionSyncService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HikvisionSyncAlcoholFlagTest extends TestCase
{
    use RefreshDatabase;

    private HikvisionTerminal $terminal;

    /** @var array<int, Employee> */
    private array $employees = [];

    protected function setUp(): void
    {
        parent::setUp();

        $accessPoint = AccessPoint::factory()->create();

        $this->terminal = HikvisionTerminal::factory()->create([
            'ip' => '127.0.0.1',
            'access_point_id' => $accessPoint->id,
            'alcohol_params' => ['enabled' => true],
        ]);

        // 10 — already exempt on the terminal, 20 — already required. Neither needs a write.
        foreach ([10, 20] as $code) {
            $employee = Employee::factory()->create(['emp_code' => $code, 'photo_path' => null]);
            $employee->accessPoints()->attach($accessPoint->id);
            $this->employees[$code] = $employee;
        }

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        // Only employee 20 must test, matching the terminal state faked below.
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')
            ->andReturn([$this->employees[20]->rusguard_uuid => true]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);
    }

    /**
     * @param  array<int, string>  $flags  emp_code => PersonInfoExtends value on the terminal
     */
    private function fakeTerminal(array $flags): void
    {
        $persons = [];

        foreach ($this->employees as $code => $employee) {
            $persons[] = [
                'employeeNo' => (string) $code,
                'name' => mb_substr($employee->full_name, 0, 32),
                'numOfCard' => 0,
                'numOfFace' => 1,
                'faceURL' => 'http://127.0.0.1/pic/'.$code.'.jpg',
                'PersonInfoExtends' => [['value' => $flags[$code]]],
            ];
        }

        Http::fake([
            '*deviceInfo*' => Http::response(['DeviceInfo' => ['deviceName' => 'test']], 200),
            '*UserInfo/Search*' => Http::response([
                'UserInfoSearch' => ['totalMatches' => count($persons), 'UserInfo' => $persons],
            ], 200),
            '*CardInfo/Search*' => Http::response([
                'CardInfoSearch' => ['totalMatches' => 0, 'CardInfo' => []],
            ], 200),
            '*FDLib/FDSearch*' => Http::response([
                'totalMatches' => count($persons),
                'MatchList' => array_map(fn ($p) => ['FPID' => $p['employeeNo']], $persons),
            ], 200),
            '*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);
    }

    private function alcoholWrites(): int
    {
        $count = 0;

        Http::assertSent(function (Request $request) use (&$count) {
            if ($request->method() === 'PUT'
                && str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp')
                && isset($request['UserInfo']['PersonInfoExtends'])) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_does_not_write_the_flag_when_the_terminal_already_matches(): void
    {
        $this->fakeTerminal([10 => HikvisionService::ALCOHOL_SKIP_FLAG, 20 => '']);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($this->terminal);

        $this->assertSame(0, $this->alcoholWrites());

        // Still counted as reconciled, so the sync summary stays truthful.
        $this->assertSame(1, $results['alcoholRequired']);
        $this->assertSame(1, $results['alcoholSkipped']);
        $this->assertSame(0, $results['alcoholFailed']);
    }

    public function test_writes_only_the_employee_whose_flag_drifted(): void
    {
        // Employee 10 should be exempt but the terminal has them as required.
        $this->fakeTerminal([10 => '', 20 => '']);

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($this->terminal);

        $this->assertSame(1, $this->alcoholWrites());

        Http::assertSent(fn (Request $request) => $request->method() !== 'PUT'
            || ! str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp')
            || $request['UserInfo']['employeeNo'] === '10');

        $this->assertSame(1, $results['alcoholRequired']);
        $this->assertSame(1, $results['alcoholSkipped']);
    }

    public function test_writes_the_flag_for_a_person_missing_from_the_terminal(): void
    {
        $this->fakeTerminal([10 => HikvisionService::ALCOHOL_SKIP_FLAG, 20 => '']);

        $newcomer = Employee::factory()->create(['emp_code' => 30, 'photo_path' => null]);
        $newcomer->accessPoints()->attach($this->terminal->access_point_id);

        app(HikvisionSyncService::class)->syncEmployeesForTerminal($this->terminal);

        // Unknown terminal state must never be mistaken for "already correct".
        $this->assertSame(1, $this->alcoholWrites());

        Http::assertSent(fn (Request $request) => $request->method() !== 'PUT'
            || ! str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp')
            || ! isset($request['UserInfo']['PersonInfoExtends'])
            || $request['UserInfo']['employeeNo'] === '30');
    }
}
