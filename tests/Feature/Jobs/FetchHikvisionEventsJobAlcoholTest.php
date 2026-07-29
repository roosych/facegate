<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FetchHikvisionEventsJob;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Turnstile;
use App\Services\HikvisionEventIngestService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class FetchHikvisionEventsJobAlcoholTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_zero_concentration_pass_and_sets_grace_period_across_linked_terminals(): void
    {
        $employee = Employee::factory()->create(['emp_code' => 555]);

        $turnstile1 = Turnstile::factory()->create();
        $turnstile2 = Turnstile::factory()->create();
        $terminal1 = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $turnstile1->id, 'ip' => '127.0.0.1']);
        $terminal2 = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $turnstile2->id, 'ip' => '127.0.0.2']);
        $employee->turnstiles()->attach([$turnstile1->id, $turnstile2->id]);

        Http::fake([
            '*/ISAPI/AccessControl/AcsEvent*' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'InfoList' => [[
                        'employeeNoString' => '555',
                        'time' => now()->toIso8601String(),
                        'currentVerifyMode' => 'faceOrCard',
                        'alcoholDetectionInfo' => [
                            'result' => 'normal',
                            'concentrationInfo' => ['concentrationValue' => 0],
                        ],
                    ]],
                ],
            ], 200),
            '*/ISAPI/AccessControl/UserInfo/SetUp*' => Http::response(['statusCode' => 1], 200),
        ]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')
            ->once()
            ->andReturn([$employee->rusguard_uuid => true]);

        $job = new FetchHikvisionEventsJob($terminal1, now()->subHour(), now());
        $job->handle($rusGuardDb, new HikvisionEventIngestService());

        $this->assertSame(1, AccessEvent::count());
        $this->assertTrue(AccessEvent::first()->alcoholPassed());

        $employee->refresh();
        $this->assertNotNull($employee->alcohol_skip_until);
        $this->assertEqualsWithDelta(now()->addMinutes(180)->timestamp, $employee->alcohol_skip_until->timestamp, 5);

        foreach ([$terminal1, $terminal2] as $terminal) {
            Http::assertSent(fn ($request) => str_contains($request->url(), $terminal->ip)
                && str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp?format=json')
                && $request['UserInfo']['employeeNo'] === '555'
                && $request['UserInfo']['PersonInfoExtends'] === [['value' => 'skip_alcohol']]);
        }
    }

    public function test_skips_events_without_alcohol_detection_info(): void
    {
        $turnstile = Turnstile::factory()->create();
        $terminal = HikvisionTerminal::factory()->create(['access_point_id' => $turnstile->id, 'ip' => '127.0.0.1']);

        Http::fake([
            '*/ISAPI/AccessControl/AcsEvent*' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'InfoList' => [[
                        'employeeNoString' => '999',
                        'time' => now()->toIso8601String(),
                        'currentVerifyMode' => 'card',
                    ]],
                ],
            ], 200),
        ]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldNotReceive('getEmployeesRequiringAlcoholTest');

        (new FetchHikvisionEventsJob($terminal, now()->subHour(), now()))->handle($rusGuardDb, new HikvisionEventIngestService());

        $this->assertSame(0, AccessEvent::count());
    }
}
