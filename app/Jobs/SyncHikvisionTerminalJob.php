<?php

namespace App\Jobs;

use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use App\Services\HikvisionSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncHikvisionTerminalJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    /**
     * Safety valve above $timeout, in case a worker dies without releasing the lock.
     */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly HikvisionTerminal $terminal,
        public readonly string $triggeredBy = SyncRun::TRIGGER_MANUAL
    ) {}

    /**
     * One in-flight sync per terminal. Both the 15-minute schedule and the RusGuard
     * audit poller queue this job, so without a lock a sync that outlives the interval
     * just accumulates duplicates behind itself that re-push state already pushed.
     */
    public function uniqueId(): string
    {
        return (string) $this->terminal->id;
    }

    public function handle(HikvisionSyncService $syncService): void
    {
        // A physical terminal is driven by exactly one environment. Bail here too, not just in
        // the scheduled command, so a manually dispatched job on a non-owning env can't push.
        if (! config('hikvision.sync_enabled')) {
            return;
        }

        // Syncing hundreds of employees in one job — including decoding/resizing large
        // face photos via GD — comfortably exceeds the default 128M CLI memory_limit.
        ini_set('memory_limit', '512M');

        $syncService->syncEmployeesForTerminal($this->terminal, $this->triggeredBy);
    }

    public function failed(Throwable $e): void
    {
        Cache::put(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$this->terminal->id, [
            'status' => 'failed',
            'terminal' => $this->terminal->name,
            'done' => 0,
            'total' => 0,
            'synced' => 0,
            'removed' => 0,
            'errors' => 0,
            'message' => $e->getMessage(),
        ], now()->addHour());
    }
}
