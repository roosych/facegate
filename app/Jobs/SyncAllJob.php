<?php

namespace App\Jobs;

use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncAllJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function handle(SyncService $syncService): void
    {
        $syncService->syncAllFromRusGuard();
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
