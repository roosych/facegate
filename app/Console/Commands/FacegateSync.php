<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\SyncRun;
use App\Services\SyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('facegate:sync {--access-point= : Sync a specific access point by ID}')]
#[Description('Sync employees from RusGuard to ZKBio CVAccess')]
class FacegateSync extends Command
{
    public function __construct(private SyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($accessPointId = $this->option('access-point')) {
            return $this->syncSingle((int) $accessPointId);
        }

        return $this->syncAll();
    }

    private function syncAll(): int
    {
        $accessPoints = AccessPoint::where('is_active', true)->get();

        if ($accessPoints->isEmpty()) {
            $this->warn('No active accessPoints found.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$accessPoints->count()} accessPoint(s)...");

        foreach ($accessPoints as $accessPoint) {
            $this->syncSingle($accessPoint->id);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function syncSingle(int $accessPointId): int
    {
        $accessPoint = AccessPoint::find($accessPointId);

        if ($accessPoint === null) {
            $this->error("Access point #{$accessPointId} not found.");

            return self::FAILURE;
        }

        $this->info("Syncing: {$accessPoint->name}...");

        try {
            $results = $this->syncService->syncEmployeesForAccessPoint($accessPoint->id, SyncRun::TRIGGER_CONSOLE);

            $this->line("  Synced: {$results['synced']}  Errors: {$results['errors']}");
        } catch (Throwable $e) {
            $this->error("  Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
