<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionEventIngestService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * The terminal is the source of truth for when a pass happened: event_time must carry the
 * device's own timestamp whichever path delivered the event, while created_at stays the
 * server's receipt time so the two can be compared.
 */
class HikvisionEventTerminalTimeTest extends TestCase
{
    use RefreshDatabase;

    /** Server clock at the moment the push arrives — 35 s behind the terminal, as on prod. */
    private const SERVER_NOW = '2026-09-21T07:30:32+04:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['hikvision.webhook_token' => 'test-token']);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        Carbon::setTestNow(Carbon::parse(self::SERVER_NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function alcoholEvent(int $serialNo, string $result = 'normal', int $mgPer100ml = 19): array
    {
        return [
            'majorEventType' => 5,
            'subEventType' => 2077,
            'cardNo' => '0518892502',
            'employeeNoString' => '42',
            'serialNo' => $serialNo,
            'currentVerifyMode' => 'cardOrFace',
            'alcoholDetectionInfo' => [
                'result' => $result,
                'concentrationInfo' => ['concentrationUnitType' => 'mg/100ml', 'concentrationValue' => $mgPer100ml],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function envelope(string $dateTime, array $event): array
    {
        return [
            'ipAddress' => '172.28.4.99',
            'portNo' => 8090,
            'protocol' => 'HTTP',
            'dateTime' => $dateTime,
            'eventType' => 'AccessControllerEvent',
            'AccessControllerEvent' => $event,
        ];
    }

    public function test_a_pushed_event_is_stored_with_the_terminal_time_not_the_receipt_time(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => json_encode($this->envelope('2026-09-21T07:31:07+04:00', $this->alcoholEvent(18594))),
        ])->assertOk();

        $event = AccessEvent::firstOrFail();

        $this->assertTrue($event->event_time->equalTo(Carbon::parse('2026-09-21T07:31:07+04:00')));
        $this->assertTrue($event->created_at->equalTo(Carbon::parse(self::SERVER_NOW)));
        $this->assertSame(35, (int) $event->event_time->diffInSeconds($event->created_at, true));
        $this->assertSame('2026-09-21T07:31:07+04:00', $event->raw_data['dateTime']);
    }

    public function test_the_stored_value_is_the_same_instant_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => json_encode($this->envelope('2026-09-21T07:31:07+04:00', $this->alcoholEvent(18594))),
        ])->assertOk();

        // 07:31:07+04:00 is 23:31:07 the previous evening in New York: it must not be stored as
        // the device's wall-clock digits into a timezone-less column read back in the app zone.
        $this->assertSame('2026-09-20 23:31:07', DB::table('access_events')->value('event_time'));
    }

    public function test_a_json_body_push_also_uses_the_terminal_time(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $this->postJson(
            "/api/hikvision/{$terminal->id}/events/test-token",
            $this->envelope('2026-09-21T07:31:07+04:00', $this->alcoholEvent(18594))
        )->assertOk();

        $this->assertTrue(AccessEvent::firstOrFail()->event_time->equalTo(Carbon::parse('2026-09-21T07:31:07+04:00')));
    }

    public function test_an_xml_push_also_uses_the_terminal_time(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<EventNotificationAlert>'
            .'<dateTime>2026-09-21T07:31:07+04:00</dateTime>'
            .'<AccessControllerEvent>'
            .'<cardNo>0518892502</cardNo><employeeNoString>42</employeeNoString><serialNo>18594</serialNo>'
            .'<alcoholDetectionInfo><result>normal</result>'
            .'<concentrationInfo><concentrationValue>19</concentrationValue></concentrationInfo>'
            .'</alcoholDetectionInfo>'
            .'</AccessControllerEvent>'
            .'</EventNotificationAlert>';

        $this->call('POST', "/api/hikvision/{$terminal->id}/events/test-token", [], [], [], ['CONTENT_TYPE' => 'application/xml'], $xml)
            ->assertOk();

        $this->assertTrue(AccessEvent::firstOrFail()->event_time->equalTo(Carbon::parse('2026-09-21T07:31:07+04:00')));
    }

    public function test_a_polled_event_uses_its_time_field_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'America/New_York']);
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        app(HikvisionEventIngestService::class)->ingest(
            $terminal,
            $this->alcoholEvent(18593, 'drinking', 42) + ['time' => '2026-09-21T07:30:54+04:00'],
            []
        );

        $this->assertSame('2026-09-20 23:30:54', DB::table('access_events')->value('event_time'));
    }

    /**
     * The incident that prompted this: a failed test (fetched, terminal time) and the retest
     * that followed 13 s later (pushed, server time) were listed in the wrong order because
     * the two used different clocks.
     */
    public function test_events_are_ordered_by_terminal_time_regardless_of_which_path_delivered_them(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        // The retest arrives first, by push.
        $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => json_encode($this->envelope('2026-09-21T07:31:07+04:00', $this->alcoholEvent(18594, 'normal', 19))),
        ])->assertOk();

        // The failed test is picked up later by the poll.
        app(HikvisionEventIngestService::class)->ingest(
            $terminal,
            $this->alcoholEvent(18593, 'drinking', 42) + ['time' => '2026-09-21T07:30:54+04:00'],
            []
        );

        $ordered = AccessEvent::orderBy('event_time')->get()->map(fn (AccessEvent $e) => $e->alcoholConcentration())->all();

        $this->assertSame([42.0, 19.0], $ordered);
    }

    public function test_an_event_without_any_timestamp_falls_back_to_server_time_and_says_so(): void
    {
        Log::spy();
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        app(HikvisionEventIngestService::class)->ingest($terminal, $this->alcoholEvent(18595), []);

        $this->assertTrue(AccessEvent::firstOrFail()->event_time->equalTo(Carbon::parse(self::SERVER_NOW)));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'no usable terminal timestamp'))->once();
    }

    public function test_an_unparseable_timestamp_does_not_reject_the_event(): void
    {
        Log::spy();
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => json_encode($this->envelope('not-a-date', $this->alcoholEvent(18596))),
        ])->assertOk();

        $this->assertSame(1, AccessEvent::count());
        $this->assertTrue(AccessEvent::firstOrFail()->event_time->equalTo(Carbon::parse(self::SERVER_NOW)));
    }

    public function test_an_event_with_its_own_time_keeps_it_over_the_envelope_date_time(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        Employee::factory()->create(['emp_code' => 42]);

        $this->post("/api/hikvision/{$terminal->id}/events/test-token", [
            'AccessControllerEvent' => json_encode($this->envelope(
                '2026-09-21T07:59:00+04:00',
                $this->alcoholEvent(18597) + ['time' => '2026-09-21T07:31:07+04:00']
            )),
        ])->assertOk();

        $this->assertTrue(AccessEvent::firstOrFail()->event_time->equalTo(Carbon::parse('2026-09-21T07:31:07+04:00')));
    }
}
