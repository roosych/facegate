<?php

namespace Tests\Unit\Services;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Turnstile;
use App\Services\HikvisionEventIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionEventIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignores_events_without_alcohol_detection_info(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $service = new HikvisionEventIngestService();

        $result = $service->ingest($terminal, ['employeeNoString' => '1', 'time' => now()->toIso8601String()], []);

        $this->assertNull($result);
        $this->assertSame(0, AccessEvent::count());
    }

    public function test_ignores_events_with_no_employee_or_card_identifier(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $service = new HikvisionEventIngestService();

        $result = $service->ingest($terminal, ['alcoholDetectionInfo' => ['result' => 'normal']], []);

        $this->assertNull($result);
    }

    public function test_creates_an_access_event_for_an_alcohol_reading(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService();

        $event = $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'currentVerifyMode' => 'faceOrCard',
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ], []);

        $this->assertNotNull($event);
        $this->assertSame($employee->id, $event->employee_id);
        $this->assertSame(1, AccessEvent::count());
    }

    public function test_does_not_duplicate_an_event_already_seen_within_the_dedupe_window(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService();

        $eventData = [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal'],
        ];

        $service->ingest($terminal, $eventData, []);
        $second = $service->ingest($terminal, $eventData, []);

        $this->assertNull($second);
        $this->assertSame(1, AccessEvent::count());
    }

    public function test_sets_grace_period_and_pushes_skip_when_employee_is_required_and_passes(): void
    {
        Http::fake(['*/ISAPI/AccessControl/UserInfo/SetUp*' => Http::response(['statusCode' => 1], 200)]);

        $turnstile = Turnstile::factory()->create();
        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $turnstile->id, 'ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $employee->turnstiles()->attach($turnstile->id);

        $service = new HikvisionEventIngestService();

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ], [$employee->rusguard_uuid => true]);

        $this->assertNotNull($employee->fresh()->alcohol_skip_until);
        Http::assertSent(fn ($request) => $request['UserInfo']['PersonInfoExtends'] === [['value' => 'skip_alcohol']]);
    }

    public function test_does_not_set_grace_period_when_employee_is_not_in_required_set(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService();

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal'],
        ], []);

        $this->assertNull($employee->fresh()->alcohol_skip_until);
    }
}
