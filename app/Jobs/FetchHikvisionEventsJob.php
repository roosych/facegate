<?php

namespace App\Jobs;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionEventIngestService;
use App\Services\HikvisionService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FetchHikvisionEventsJob implements ShouldQueue
{
    use Queueable;

    public const STATUS_KEY = 'hikvision_fetch_events_status';

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly HikvisionTerminal $terminal,
        public readonly Carbon $startTime,
        public readonly Carbon $endTime,
    ) {}

    public function handle(RusGuardDatabaseService $rusGuardDb, HikvisionEventIngestService $ingestService): void
    {
        Cache::put(self::STATUS_KEY.'_'.$this->terminal->id, [
            'status'   => 'running',
            'terminal' => $this->terminal->name,
            'imported' => 0,
            'total'    => 0,
        ], now()->addHour());

        $service   = new HikvisionService($this->terminal);
        $rawEvents = $service->fetchAccessEvents($this->startTime, $this->endTime);
        $imported  = 0;

        // Lazily resolved uuid => true map from RusGuard's AlcoGroup config — only fetched
        // once per run, if an alcohol pass is actually encountered below.
        $alcoholRequired = null;

        foreach ($rawEvents as $eventData) {
            if (isset($eventData['alcoholDetectionInfo'])) {
                $alcoholRequired ??= $rusGuardDb->getEmployeesRequiringAlcoholTest();
            }

            if ($ingestService->ingest($this->terminal, $eventData, $alcoholRequired ?? []) !== null) {
                $imported++;
            }
        }

        Cache::put(self::STATUS_KEY.'_'.$this->terminal->id, [
            'status'   => 'done',
            'terminal' => $this->terminal->name,
            'imported' => $imported,
            'total'    => count($rawEvents),
        ], now()->addHour());
    }

    public function failed(Throwable $e): void
    {
        Cache::put(self::STATUS_KEY.'_'.$this->terminal->id, [
            'status'   => 'failed',
            'terminal' => $this->terminal->name,
            'imported' => 0,
            'total'    => 0,
            'message'  => $e->getMessage(),
        ], now()->addHour());
    }
}
