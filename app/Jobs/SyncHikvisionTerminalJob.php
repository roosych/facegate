<?php

namespace App\Jobs;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncHikvisionTerminalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly HikvisionTerminal $terminal) {}

    public function handle(HikvisionSyncService $syncService): void
    {
        // Syncing hundreds of employees in one job — including decoding/resizing large
        // face photos via GD — comfortably exceeds the default 128M CLI memory_limit.
        ini_set("memory_limit", "512M");

        $syncService->syncEmployeesForTerminal($this->terminal);
    }

    public function failed(Throwable $e): void
    {
        Cache::put(HikvisionSyncService::SYNC_STATUS_KEY."_".$this->terminal->id, [
            "status" => "failed",
            "terminal" => $this->terminal->name,
            "done" => 0,
            "total" => 0,
            "synced" => 0,
            "removed" => 0,
            "errors" => 0,
            "message" => $e->getMessage(),
        ], now()->addHour());
    }
}
