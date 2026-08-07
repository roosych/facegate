<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Models\Turnstile;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncTurnstileJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly Turnstile $turnstile,
        public readonly string $triggeredBy = SyncRun::TRIGGER_MANUAL
    ) {}

    public function handle(SyncService $syncService): void
    {
        $syncService->syncEmployeesForTurnstile($this->turnstile->id, $this->triggeredBy);
    }

    public function failed(Throwable $e): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY.'_'.$this->turnstile->id, [
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
