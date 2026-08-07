<?php

namespace Tests\Feature\Console;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClearExpiredAlcoholSkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_expired_skip_and_pushes_to_terminal(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1], 200)]);

        $accessPoint = AccessPoint::factory()->create();
        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $accessPoint->id, 'ip' => '127.0.0.1']);

        $employee = Employee::factory()->create(['emp_code' => 555, 'alcohol_skip_until' => now()->subMinute()]);
        $employee->accessPoints()->attach($accessPoint->id);

        $this->artisan('alcohol:clear-expired-skip')->assertExitCode(0);

        $this->assertNull($employee->fresh()->alcohol_skip_until);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp?format=json')
            && $request['UserInfo']['employeeNo'] === '555'
            && $request['UserInfo']['PersonInfoExtends'] === [['value' => '']]);
    }

    public function test_leaves_active_skip_untouched(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1], 200)]);

        $employee = Employee::factory()->create(['alcohol_skip_until' => now()->addMinutes(30)]);

        $this->artisan('alcohol:clear-expired-skip')->assertExitCode(0);

        $this->assertNotNull($employee->fresh()->alcohol_skip_until);
        Http::assertNothingSent();
    }
}
