<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncAllJob;
use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use App\Services\RusGuard\RusGuardDatabaseService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RusGuardPollAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstraps_cursor_on_first_run_without_syncing(): void
    {
        Bus::fake();

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getLatestAuditId')->once()->andReturn(1000);
        $rusGuardDb->shouldNotReceive('getRelevantAuditEventsSince');
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $this->artisan('rusguard:poll-audit')->assertExitCode(0);

        $this->assertSame(1000, DB::table('rusguard_audit_cursor')->where('id', 1)->value('last_audit_id'));
        Bus::assertNothingDispatched();
    }

    public function test_does_nothing_when_no_new_events(): void
    {
        Bus::fake();
        DB::table('rusguard_audit_cursor')->where('id', 1)->update(['last_audit_id' => 500]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getLatestAuditId')->once()->andReturn(500);
        $rusGuardDb->shouldNotReceive('getRelevantAuditEventsSince');
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $this->artisan('rusguard:poll-audit')->assertExitCode(0);

        Bus::assertNothingDispatched();
    }

    public function test_resyncs_when_relevant_events_found(): void
    {
        Bus::fake();
        DB::table('rusguard_audit_cursor')->where('id', 1)->update(['last_audit_id' => 500]);

        HikvisionTerminal::factory()->create(['is_active' => true]);
        HikvisionTerminal::factory()->create(['is_active' => false]);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getLatestAuditId')->once()->andReturn(510);
        $rusGuardDb->shouldReceive('getRelevantAuditEventsSince')->once()->with(500)->andReturn([
            ['id' => 505, 'dateTime' => '2026-01-01T00:00:00', 'msgTypeId' => 22, 'message' => 'test', 'details' => 'x', 'employeeUuid' => null],
        ]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $this->artisan('rusguard:poll-audit')->assertExitCode(0);

        $this->assertSame(510, DB::table('rusguard_audit_cursor')->where('id', 1)->value('last_audit_id'));

        Bus::assertChained([
            SyncAllJob::class,
            SyncHikvisionTerminalJob::class,
        ]);
    }

    public function test_skips_dispatch_when_a_resync_is_already_running(): void
    {
        Bus::fake();
        DB::table('rusguard_audit_cursor')->where('id', 1)->update(['last_audit_id' => 500]);
        Cache::put(SyncService::SYNC_STATUS_KEY, ['status' => 'running']);

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getLatestAuditId')->once()->andReturn(510);
        $rusGuardDb->shouldReceive('getRelevantAuditEventsSince')->once()->with(500)->andReturn([
            ['id' => 505, 'dateTime' => '2026-01-01T00:00:00', 'msgTypeId' => 22, 'message' => 'test', 'details' => 'x', 'employeeUuid' => null],
        ]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $this->artisan('rusguard:poll-audit')->assertExitCode(0);

        $this->assertSame(510, DB::table('rusguard_audit_cursor')->where('id', 1)->value('last_audit_id'));
        Bus::assertNothingDispatched();
    }
}
