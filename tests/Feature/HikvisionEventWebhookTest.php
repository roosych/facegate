<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class HikvisionEventWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hikvision.webhook_token' => 'test-token']);
    }

    public function test_rejects_a_wrong_token(): void
    {
        $terminal = HikvisionTerminal::factory()->create();

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/wrong-token", []);

        $response->assertStatus(403);
    }

    public function test_ingests_a_multipart_access_controller_event(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $payload = json_encode([
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'currentVerifyMode' => 'faceOrCard',
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ]);

        $response = $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => $payload,
        ]);

        $response->assertOk();
        $this->assertSame(1, AccessEvent::count());
    }

    /**
     * The device wraps real (non-heartbeat) events in an outer envelope — ipAddress, dateTime,
     * eventType, etc. — with the actual per-event data (employeeNoString, cardNo,
     * alcoholDetectionInfo) nested one level deeper under its own "AccessControllerEvent" key.
     * Only the flat shape (no outer wrapper) was covered before, which is why a regression here
     * silently dropped every real multipart event while heartbeats kept working fine.
     */
    public function test_ingests_a_multipart_access_controller_event_with_nested_wrapper(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $payload = json_encode([
            'ipAddress' => '192.0.2.99',
            'dateTime' => now()->toIso8601String(),
            'eventType' => 'AccessControllerEvent',
            'eventState' => 'active',
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
                'currentVerifyMode' => 'faceOrCard',
                'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
            ],
        ]);

        $response = $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => $payload,
        ]);

        $response->assertOk();
        $this->assertSame(1, AccessEvent::count());
    }

    public function test_ingests_a_plain_json_envelope(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
                'alcoholDetectionInfo' => ['result' => 'normal'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(1, AccessEvent::count());
    }

    public function test_does_not_query_rusguard_for_non_alcohol_events(): void
    {
        $terminal = HikvisionTerminal::factory()->create();

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldNotReceive('getEmployeesRequiringAlcoholTest');
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0, AccessEvent::count());
    }

    /**
     * The monitor screen needs every identified pass, not just ones with an alcohol reading —
     * access_events (via ingest()) intentionally stays alcohol-only, so this caches a separate,
     * lightweight snapshot for display purposes.
     */
    public function test_caches_the_latest_event_for_the_monitor_screen_even_without_alcohol_data(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $employee = Employee::factory()->create([
            'emp_code' => 42,
            'last_name' => 'Petrov',
            'position' => 'Mütəxəssis',
            'department' => 'İT şöbəsi',
        ]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldNotReceive('getEmployeesRequiringAlcoholTest');
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0, AccessEvent::count());

        $cached = Cache::get($terminal->fresh()->monitorCacheKey());
        $this->assertNotNull($cached);
        $this->assertSame($employee->id, $cached['employee_id']);
        $this->assertSame('Mütəxəssis', $cached['position']);
        $this->assertSame('İT şöbəsi', $cached['department']);
        $this->assertFalse($cached['alcohol_tested']);
        $this->assertNull($cached['alcohol_passed']);
    }

    public function test_monitor_cache_uses_the_terminal_time_not_the_server_clock(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => '2026-09-21T07:31:07+04:00',
            ],
        ])->assertOk();

        $cached = Cache::get($terminal->fresh()->monitorCacheKey());
        $this->assertSame('2026-09-20T23:31:07-04:00', $cached['event_time']);
    }

    public function test_caches_a_failed_alcohol_test_for_the_monitor_screen(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
                'alcoholDetectionInfo' => ['result' => 'abnormal', 'concentrationInfo' => ['concentrationValue' => 55]],
            ],
        ]);

        $response->assertOk();

        $cached = Cache::get($terminal->fresh()->monitorCacheKey());
        $this->assertTrue($cached['alcohol_tested']);
        $this->assertFalse($cached['alcohol_passed']);
        $this->assertSame(55, $cached['alcohol_concentration']);
    }

    public function test_ingests_an_alcohol_detection_event_key(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [
            'eventType' => 'AlcoholDetectionEvent',
            'AlcoholDetectionEvent' => [
                'employeeNoString' => '42',
                'time' => now()->toIso8601String(),
                'alcoholDetectionInfo' => ['result' => 'normal'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(1, AccessEvent::count());
    }

    public function test_ingests_an_xml_body(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->once()->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $time = now()->toIso8601String();
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <EventNotificationAlert version="2.0" xmlns="http://www.hikvision.com/ver20/XMLSchema">
            <eventType>AccessControllerEvent</eventType>
            <AccessControllerEvent>
            <employeeNoString>42</employeeNoString>
            <time>{$time}</time>
            <alcoholDetectionInfo>
            <result>normal</result>
            </alcoholDetectionInfo>
            </AccessControllerEvent>
            </EventNotificationAlert>
            XML;

        $response = $this->call('POST', "/api/hikvision/{$terminal->id}/events/test-token", [], [], [], [
            'CONTENT_TYPE' => 'application/xml',
        ], $xml);

        $response->assertOk();
        $this->assertSame(1, AccessEvent::count());
    }
}
