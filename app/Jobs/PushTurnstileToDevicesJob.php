<?php

namespace App\Jobs;

use App\Models\Turnstile;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PushTurnstileToDevicesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly Turnstile $turnstile) {}

    public function handle(SyncService $syncService): void
    {
        $syncService->pushTurnstileToDevices($this->turnstile->id);
    }

    /**
     * pushTurnstileToDevices() marks the shared status key as running and only clears it on
     * a clean finish, so without this the key stayed 'running' for its full hour TTL. That
     * key is also what rusguard:poll-audit checks before queueing a resync, meaning a failure
     * here used to stall RusGuard resyncs entirely until the entry expired.
     */
    public function failed(Throwable $e): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, [
            'status' => 'failed',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'emp_done' => 0,
            'emp_total' => 0,
            'synced' => 0,
            'errors' => 0,
            'message' => $e->getMessage(),
        ], now()->addHour());
    }
}
