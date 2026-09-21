<?php

namespace Tests\Unit\Services;

use App\Mail\AlcoholTestFailedMail;
use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use App\Services\HikvisionEventIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HikvisionEventIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ignores_events_without_alcohol_detection_info(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $service = new HikvisionEventIngestService;

        $result = $service->ingest($terminal, ['employeeNoString' => '1', 'time' => now()->toIso8601String()], []);

        $this->assertNull($result);
        $this->assertSame(0, AccessEvent::count());
    }

    public function test_ignores_events_with_no_employee_or_card_identifier(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $service = new HikvisionEventIngestService;

        $result = $service->ingest($terminal, ['alcoholDetectionInfo' => ['result' => 'normal']], []);

        $this->assertNull($result);
    }

    public function test_creates_an_access_event_for_an_alcohol_reading(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

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
        $service = new HikvisionEventIngestService;

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

    /**
     * Real-time push payloads carry no per-event "time" field (only the outer envelope's
     * heartbeat-style timestamp does), so ingest() falls back to now() for $eventTime on every
     * push-delivered event. Two genuinely distinct passes seconds apart — same employee, same
     * card, same reading — used to collide in the old ±30s time-window dedup and get the second
     * one silently dropped. serialNo (present on every real device payload, push or polled)
     * distinguishes them.
     */
    public function test_does_not_deduplicate_distinct_events_with_different_serial_numbers_seconds_apart(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $first = $service->ingest($terminal, [
            'employeeNoString' => '42',
            'cardNo' => '0000000042',
            'serialNo' => 32,
            'alcoholDetectionInfo' => ['result' => 'normal'],
        ], []);

        $second = $service->ingest($terminal, [
            'employeeNoString' => '42',
            'cardNo' => '0000000042',
            'serialNo' => 38,
            'alcoholDetectionInfo' => ['result' => 'normal'],
        ], []);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, AccessEvent::count());
    }

    public function test_deduplicates_the_same_serial_number_delivered_twice(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $eventData = [
            'employeeNoString' => '42',
            'cardNo' => '0000000042',
            'serialNo' => 32,
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

        $accessPoint = AccessPoint::factory()->create();
        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $accessPoint->id, 'ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $employee->accessPoints()->attach($accessPoint->id);

        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ], [$employee->rusguard_uuid => true]);

        $this->assertNotNull($employee->fresh()->alcohol_skip_until);
        Http::assertSent(fn ($request) => $request['UserInfo']['PersonInfoExtends'] === [['value' => 'skip_alcohol']]);
    }

    /**
     * @return array{0: HikvisionTerminal, 1: Employee}
     */
    private function requiredEmployeeOnAlcoholTerminal(): array
    {
        $accessPoint = AccessPoint::factory()->create();
        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['access_point_id' => $accessPoint->id, 'ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $employee->accessPoints()->attach($accessPoint->id);

        return [$terminal, $employee];
    }

    /**
     * @return array<string, mixed>
     */
    private function passedTestAt(Carbon $time, int $serialNo): array
    {
        return [
            'employeeNoString' => '42',
            'serialNo' => $serialNo,
            'time' => $time->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ];
    }

    public function test_grace_period_is_counted_from_the_time_of_the_pass_not_from_processing(): void
    {
        Http::fake(['*/ISAPI/AccessControl/UserInfo/SetUp*' => Http::response(['statusCode' => 1], 200)]);
        Carbon::setTestNow('2026-09-21 08:00:00');
        Setting::set('alcohol_skip_grace_minutes', '180');
        [$terminal, $employee] = $this->requiredEmployeeOnAlcoholTerminal();

        // Passed at 07:30 but only picked up by the poll at 08:00.
        (new HikvisionEventIngestService)->ingest(
            $terminal,
            $this->passedTestAt(Carbon::parse('2026-09-21 07:30:00'), 1),
            [$employee->rusguard_uuid => true]
        );

        $this->assertSame('2026-09-21 10:30:00', $employee->fresh()->alcohol_skip_until->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($request) => $request['UserInfo']['PersonInfoExtends'] === [['value' => 'skip_alcohol']]);

        Carbon::setTestNow();
    }

    public function test_a_pass_older_than_the_grace_period_grants_no_exemption(): void
    {
        Http::fake();
        Carbon::setTestNow('2026-09-21 12:00:00');
        Setting::set('alcohol_skip_grace_minutes', '180');
        [$terminal, $employee] = $this->requiredEmployeeOnAlcoholTerminal();

        // Passed 4 h ago: its 180-minute window ended an hour ago.
        (new HikvisionEventIngestService)->ingest(
            $terminal,
            $this->passedTestAt(Carbon::parse('2026-09-21 08:00:00'), 1),
            [$employee->rusguard_uuid => true]
        );

        $this->assertNull($employee->fresh()->alcohol_skip_until);
        $this->assertSame(1, AccessEvent::count());
        Http::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_an_older_pass_processed_late_does_not_shorten_a_longer_window(): void
    {
        Http::fake();
        Carbon::setTestNow('2026-09-21 09:00:00');
        Setting::set('alcohol_skip_grace_minutes', '180');
        [$terminal, $employee] = $this->requiredEmployeeOnAlcoholTerminal();
        $employee->update(['alcohol_skip_until' => Carbon::parse('2026-09-21 12:00:00')]);

        // A pass from 08:00 (window to 11:00) arrives after a later pass already set 12:00.
        (new HikvisionEventIngestService)->ingest(
            $terminal,
            $this->passedTestAt(Carbon::parse('2026-09-21 08:00:00'), 1),
            [$employee->rusguard_uuid => true]
        );

        $this->assertSame('2026-09-21 12:00:00', $employee->fresh()->alcohol_skip_until->format('Y-m-d H:i:s'));
        Http::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_a_later_pass_extends_the_window(): void
    {
        Http::fake(['*/ISAPI/AccessControl/UserInfo/SetUp*' => Http::response(['statusCode' => 1], 200)]);
        Carbon::setTestNow('2026-09-21 09:00:00');
        Setting::set('alcohol_skip_grace_minutes', '180');
        [$terminal, $employee] = $this->requiredEmployeeOnAlcoholTerminal();
        $employee->update(['alcohol_skip_until' => Carbon::parse('2026-09-21 10:30:00')]);

        (new HikvisionEventIngestService)->ingest(
            $terminal,
            $this->passedTestAt(Carbon::parse('2026-09-21 09:00:00'), 1),
            [$employee->rusguard_uuid => true]
        );

        $this->assertSame('2026-09-21 12:00:00', $employee->fresh()->alcohol_skip_until->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_does_not_set_grace_period_when_employee_is_not_in_required_set(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        $employee = Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal'],
        ], []);

        $this->assertNull($employee->fresh()->alcohol_skip_until);
    }

    public function test_emails_recipients_when_a_failed_test_is_at_or_above_the_threshold(): void
    {
        Mail::fake();
        Setting::set('alcohol_notification_threshold', '20');
        Setting::set('alcohol_notification_emails', 'security@example.com,hr@example.com');

        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'drinking', 'concentrationInfo' => ['concentrationValue' => 25]],
        ], []);

        Mail::assertQueued(AlcoholTestFailedMail::class, fn ($mail) => $mail->hasTo('security@example.com') && $mail->hasTo('hr@example.com'));
    }

    public function test_does_not_email_when_concentration_is_below_the_threshold(): void
    {
        Mail::fake();
        Setting::set('alcohol_notification_threshold', '20');
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'drinking', 'concentrationInfo' => ['concentrationValue' => 10]],
        ], []);

        Mail::assertNotQueued(AlcoholTestFailedMail::class);
    }

    public function test_does_not_email_when_no_recipients_are_configured(): void
    {
        Mail::fake();
        Setting::set('alcohol_notification_threshold', '20');

        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'drinking', 'concentrationInfo' => ['concentrationValue' => 25]],
        ], []);

        Mail::assertNotQueued(AlcoholTestFailedMail::class);
    }

    public function test_does_not_email_on_a_passed_test(): void
    {
        Mail::fake();
        Setting::set('alcohol_notification_threshold', '0');
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);
        $service = new HikvisionEventIngestService;

        $service->ingest($terminal, [
            'employeeNoString' => '42',
            'time' => now()->toIso8601String(),
            'alcoholDetectionInfo' => ['result' => 'normal', 'concentrationInfo' => ['concentrationValue' => 0]],
        ], []);

        Mail::assertNotQueued(AlcoholTestFailedMail::class);
    }
}
