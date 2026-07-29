<?php

namespace App\Jobs;

use App\Models\Turnstile;
use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
