<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncAllJob;
use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SyncJobUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_sync_per_terminal_can_be_queued_at_a_time(): void
    {
        Queue::fake();

        $first = HikvisionTerminal::factory()->create();
        $second = HikvisionTerminal::factory()->create();

        SyncHikvisionTerminalJob::dispatch($first);
        SyncHikvisionTerminalJob::dispatch($first);
        SyncHikvisionTerminalJob::dispatch($second);

        // The duplicate for $first is dropped, but a different terminal is unaffected.
        Queue::assertPushed(SyncHikvisionTerminalJob::class, 2);
    }

    public function test_only_one_org_wide_resync_can_be_queued_at_a_time(): void
    {
        Queue::fake();

        SyncAllJob::dispatch();
        SyncAllJob::dispatch();

        Queue::assertPushed(SyncAllJob::class, 1);
    }

    public function test_a_failed_resync_releases_the_shared_sync_status(): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, ['status' => 'running'], now()->addHour());

        (new SyncAllJob)->failed(new RuntimeException('RusGuard unreachable'));

        $status = Cache::get(SyncService::SYNC_STATUS_KEY);

        // Left as 'running' this used to stall rusguard:poll-audit for the full hour TTL.
        $this->assertSame('failed', $status['status']);
        $this->assertSame('RusGuard unreachable', $status['message']);
    }
}
