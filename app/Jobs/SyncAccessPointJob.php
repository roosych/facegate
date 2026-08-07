<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Models\AccessPoint;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncAccessPointJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly AccessPoint $accessPoint,
        public readonly string $triggeredBy = SyncRun::TRIGGER_MANUAL
    ) {}

    public function handle(SyncService $syncService): void
    {
        $syncService->syncEmployeesForAccessPoint($this->accessPoint->id, $this->triggeredBy);
    }

    public function failed(Throwable $e): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY.'_'.$this->accessPoint->id, [
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
