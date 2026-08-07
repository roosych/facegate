<?php

namespace Tests\Feature;

use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MonitoringSyncRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_successful_run_with_its_duration_and_counters(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['name' => 'Post 1']);

        $stats = SyncRun::track(
            SyncRun::KIND_HIKVISION,
            SyncRun::TRIGGER_SCHEDULE,
            ['hikvision_terminal_id' => $terminal->id],
            fn (): array => ['synced' => 665, 'faces' => 616, 'errors' => 0],
        );

        $this->assertSame(['synced' => 665, 'faces' => 616, 'errors' => 0], $stats);

        $run = SyncRun::sole();

        $this->assertSame(SyncRun::KIND_HIKVISION, $run->kind);
        $this->assertSame(SyncRun::TRIGGER_SCHEDULE, $run->triggered_by);
        $this->assertSame(SyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame($terminal->id, $run->hikvision_terminal_id);
        $this->assertSame(['synced' => 665, 'faces' => 616, 'errors' => 0], $run->stats);
        $this->assertNotNull($run->finished_at);
        $this->assertIsInt($run->duration_ms);
        $this->assertNull($run->message);
    }

    /**
     * The run has to survive the failure it is meant to explain — the queue still owns the
     * exception, so it is re-thrown untouched after the row is stamped.
     */
    public function test_records_a_failed_run_and_rethrows(): void
    {
        try {
            SyncRun::track(
                SyncRun::KIND_RUSGUARD,
                SyncRun::TRIGGER_AUDIT,
                [],
                fn (): array => throw new RuntimeException('Terminal is offline or unreachable'),
            );

            $this->fail('The exception should have been re-thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Terminal is offline or unreachable', $e->getMessage());
        }

        $run = SyncRun::sole();

        $this->assertSame(SyncRun::STATUS_FAILED, $run->status);
        $this->assertSame('Terminal is offline or unreachable', $run->message);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([], $run->stats);
    }

    /**
     * A run in flight must be visible while it runs, otherwise a sync that hangs looks
     * exactly like a sync that never started.
     */
    public function test_the_row_exists_while_the_run_is_still_in_flight(): void
    {
        SyncRun::track(SyncRun::KIND_RUSGUARD, SyncRun::TRIGGER_MANUAL, [], function (): array {
            $inFlight = SyncRun::sole();

            $this->assertSame(SyncRun::STATUS_RUNNING, $inFlight->status);
            $this->assertNull($inFlight->finished_at);

            return ['synced' => 1];
        });

        $this->assertSame(SyncRun::STATUS_SUCCESS, SyncRun::sole()->status);
    }

    /**
     * The services return per-employee detail alongside their counters; that belongs in
     * sync_logs, and copying it here would turn a run summary into a second log table.
     */
    public function test_keeps_only_scalar_counters_out_of_the_returned_stats(): void
    {
        SyncRun::track(
            SyncRun::KIND_TURNSTILE,
            SyncRun::TRIGGER_CONSOLE,
            [],
            fn (): array => ['synced' => 3, 'errors' => 0, 'employees' => [['id' => 1], ['id' => 2]]],
        );

        $this->assertSame(['synced' => 3, 'errors' => 0], SyncRun::sole()->stats);
    }

    public function test_monitoring_page_lists_runs_and_filters_by_kind(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['name' => 'Post 1']);

        SyncRun::factory()->create([
            'kind' => SyncRun::KIND_RUSGUARD,
            'started_at' => now()->subHours(2),
            'duration_ms' => 95_000,
            'stats' => ['synced' => 4917],
        ]);
        SyncRun::factory()->create([
            'kind' => SyncRun::KIND_HIKVISION,
            'hikvision_terminal_id' => $terminal->id,
            'started_at' => now()->subHour(),
            'duration_ms' => 14_000,
            'stats' => ['synced' => 6653],
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('monitoring.index'))
            ->assertOk()
            ->assertSee('1m 35s')
            ->assertSee('14s')
            ->assertSee('Post 1')
            ->assertSee('4917')
            ->assertSee('6653');

        // The counters identify the row; durations also appear in the 24-hour summary above
        // the table, which deliberately stays unfiltered.
        $this->actingAs($user)->get(route('monitoring.index', ['kind' => SyncRun::KIND_HIKVISION]))
            ->assertOk()
            ->assertSee('6653')
            ->assertDontSee('4917');
    }

    public function test_prunes_runs_older_than_ninety_days(): void
    {
        $old = SyncRun::factory()->create(['started_at' => now()->subDays(91)]);
        $recent = SyncRun::factory()->create(['started_at' => now()->subDays(89)]);

        $this->artisan('model:prune', ['--model' => [SyncRun::class]])->assertSuccessful();

        $this->assertDatabaseMissing('sync_runs', ['id' => $old->id]);
        $this->assertDatabaseHas('sync_runs', ['id' => $recent->id]);
    }
}
