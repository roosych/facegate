<?php

namespace Tests\Feature;

use App\Jobs\SyncAllJob;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\HikvisionSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitoringHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['hikvision.webhook_token' => 'test-token']);
    }

    public function test_a_webhook_hit_stamps_the_terminals_last_push(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['last_push_at' => now()->subDay()]);

        // A bare heartbeat carries no event data, and still proves push is alive.
        $this->postJson("/api/hikvision/{$terminal->id}/events/test-token", [])
            ->assertOk();

        $this->assertTrue($terminal->fresh()->last_push_at->isToday());
    }

    public function test_a_wrong_token_does_not_stamp_the_last_push(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['last_push_at' => null]);

        $this->postJson("/api/hikvision/{$terminal->id}/events/wrong-token", [])
            ->assertStatus(403);

        $this->assertNull($terminal->fresh()->last_push_at);
    }

    /**
     * The failure this block exists for: events keep arriving on the 30-minute poll, so
     * nothing looks broken while the terminal has actually stopped pushing.
     */
    public function test_reports_a_terminal_whose_push_went_quiet_despite_recent_events(): void
    {
        $terminal = HikvisionTerminal::factory()->create([
            'name' => 'Post 1',
            'is_active' => true,
            'last_push_at' => now()->subHours(3),
        ]);

        AccessEvent::factory()->create([
            'hikvision_terminal_id' => $terminal->id,
            'created_at' => now()->subMinutes(2),
        ]);

        $health = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->assertOk()
            ->json('health');

        $this->assertSame('Post 1', $health['terminals'][0]['name']);
        $this->assertTrue($health['terminals'][0]['push_stale']);
        $this->assertNotNull($health['terminals'][0]['last_event_at']);
    }

    public function test_a_terminal_pushing_normally_is_not_flagged(): void
    {
        HikvisionTerminal::factory()->create(['last_push_at' => now()->subMinutes(10)]);

        $health = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->json('health');

        $this->assertFalse($health['terminals'][0]['push_stale']);
    }

    public function test_flags_an_audit_poller_that_stopped_running(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(
            ['id' => 1],
            ['last_audit_id' => 100, 'polled_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]
        );

        $health = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->json('health');

        $this->assertTrue($health['rusguard']['audit_stale']);
    }

    /**
     * The audit log is idle most of the time, so the cursor rarely moves. Polling has to be
     * judged on its own timestamp, or a healthy quiet system reads as a dead poller.
     */
    public function test_an_idle_but_healthy_poller_is_not_flagged(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(
            ['id' => 1],
            [
                'last_audit_id' => 100,
                'polled_at' => now()->subSeconds(30),
                // Cursor last advanced hours ago — nothing happened in RusGuard since.
                'updated_at' => now()->subHours(6),
            ]
        );

        $health = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->json('health');

        $this->assertFalse($health['rusguard']['audit_stale']);
    }

    public function test_groups_pending_jobs_and_lists_failed_ones(): void
    {
        DB::table('jobs')->insert([
            ['queue' => 'default', 'payload' => json_encode(['displayName' => 'App\Jobs\SyncAllJob']), 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(3)->timestamp, 'created_at' => now()->timestamp],
            ['queue' => 'default', 'payload' => json_encode(['displayName' => 'App\Jobs\SyncAllJob']), 'attempts' => 0, 'reserved_at' => now()->timestamp, 'available_at' => now()->subMinute()->timestamp, 'created_at' => now()->timestamp],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => 'abc-123',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SyncHikvisionTerminalJob']),
            'exception' => "RuntimeException: Terminal is offline\n#0 stack frame",
            'failed_at' => now(),
        ]);

        $queue = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->json('queue');

        $this->assertSame('SyncAllJob', $queue['pending'][0]['job']);
        $this->assertSame(2, $queue['pending'][0]['count']);
        $this->assertSame(1, $queue['reserved']);
        $this->assertSame('SyncHikvisionTerminalJob', $queue['failed'][0]['job']);
        $this->assertSame('RuntimeException: Terminal is offline', $queue['failed'][0]['error']);
    }

    public function test_retrying_a_failed_job_puts_it_back_on_the_queue(): void
    {
        Queue::fake();

        DB::table('failed_jobs')->insert([
            'uuid' => 'abc-123',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SyncAllJob', 'data' => ['commandName' => 'App\Jobs\SyncAllJob', 'command' => serialize(new SyncAllJob)]]),
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.failed-jobs.retry', 'abc-123'))
            ->assertRedirect(route('monitoring.index'));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'abc-123']);
    }

    public function test_forgetting_a_failed_job_drops_it(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'abc-123',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SyncAllJob']),
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.failed-jobs.forget', 'abc-123'))
            ->assertRedirect(route('monitoring.index'));

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'abc-123']);
    }

    /**
     * "No photo in RusGuard" and "the device refused the photo we have" need different
     * fixes, so counting them together would hide which one is actually happening.
     */
    public function test_splits_face_problems_by_cause_and_names_the_people(): void
    {
        Employee::factory()->create(['emp_code' => 36, 'last_name' => 'Иванов', 'first_name' => 'Иван', 'middle_name' => null]);
        Employee::factory()->create(['emp_code' => 85, 'last_name' => 'Петров', 'first_name' => 'Пётр', 'middle_name' => null]);

        HikvisionTerminal::factory()->create([
            'name' => 'Post 1',
            'sync_stats' => [
                'face_problems' => [
                    36 => HikvisionSyncService::NO_LOCAL_PHOTO,
                    85 => '43c4b6f49e89f1625a8895cfbc5f6610',
                ],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitoring.index'))
            ->assertOk()
            ->assertSee('No photo in RusGuard · 1')
            ->assertSee('Refused by the terminal · 1')
            ->assertSee('Иванов Иван')
            ->assertSee('Петров Пётр');
    }

    public function test_clearing_face_problems_lets_the_next_sync_retry_them(): void
    {
        $terminal = HikvisionTerminal::factory()->create([
            'sync_stats' => [
                'faces_added' => 616,
                'face_problems' => [36 => HikvisionSyncService::NO_LOCAL_PHOTO],
            ],
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.face-problems.clear', $terminal))
            ->assertRedirect(route('monitoring.index'));

        $stats = $terminal->fresh()->sync_stats;

        $this->assertArrayNotHasKey('face_problems', $stats);
        $this->assertSame(616, $stats['faces_added'], 'The rest of the terminal stats must survive.');
    }

    public function test_shows_the_last_successful_rusguard_run_with_its_duration(): void
    {
        SyncRun::factory()->create([
            'kind' => SyncRun::KIND_RUSGUARD,
            'status' => SyncRun::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(30),
            'duration_ms' => 95_000,
        ]);

        $health = $this->actingAs(User::factory()->create())
            ->getJson(route('monitoring.status'))
            ->json('health');

        $this->assertSame('1m 35s', $health['rusguard']['last_sync_duration']);
    }
}
