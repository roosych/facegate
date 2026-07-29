<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
