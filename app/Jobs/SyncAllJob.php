<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncAllJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * An org-wide resync reads current RusGuard state, so a second one queued behind the
     * first would only repeat work it already covered. Safety valve above $timeout.
     */
    public int $uniqueFor = 4200;

    public function __construct(public readonly string $triggeredBy = SyncRun::TRIGGER_MANUAL) {}

    public function handle(SyncService $syncService): void
    {
        $syncService->syncAllFromRusGuard($this->triggeredBy);
    }

    public function failed(Throwable $e): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, [
            'status' => 'failed',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'synced' => 0,
            'errors' => 0,
            'message' => $e->getMessage(),
        ], now()->addHour());
    }
}
