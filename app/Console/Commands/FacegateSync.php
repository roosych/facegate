<?php

namespace App\Console\Commands;

use App\Models\Turnstile;
use App\Services\SyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('facegate:sync {--turnstile= : Sync a specific turnstile by ID}')]
#[Description('Sync employees from RusGuard to ZKBio CVAccess')]
class FacegateSync extends Command
{
    public function __construct(private SyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($turnstileId = $this->option('turnstile')) {
            return $this->syncSingle((int) $turnstileId);
        }

        return $this->syncAll();
    }

    private function syncAll(): int
    {
        $turnstiles = Turnstile::where('is_active', true)->get();

        if ($turnstiles->isEmpty()) {
            $this->warn('No active turnstiles found.');

            return self::SUCCESS;
        }

        $this->info("Syncing {$turnstiles->count()} turnstile(s)...");

        foreach ($turnstiles as $turnstile) {
            $this->syncSingle($turnstile->id);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function syncSingle(int $turnstileId): int
    {
        $turnstile = Turnstile::find($turnstileId);

        if ($turnstile === null) {
            $this->error("Turnstile #{$turnstileId} not found.");

            return self::FAILURE;
        }

        $this->info("Syncing: {$turnstile->name}...");

        try {
            $results = $this->syncService->syncEmployeesForTurnstile($turnstile->id);

            $this->line("  Synced: {$results['synced']}  Errors: {$results['errors']}");
        } catch (Throwable $e) {
            $this->error("  Failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
