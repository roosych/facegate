<?php

namespace App\Console\Commands;

use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hikvision:sync-all')]
#[Description('Queue a sync job for every active Hikvision terminal (persons, cards, faces, alcohol skip flags)')]
class SyncAllHikvisionTerminals extends Command
{
    public function handle(): int
    {
        if (! config('hikvision.sync_enabled')) {
            $this->warn('Hikvision sync is disabled in this environment (config hikvision.sync_enabled). Skipping.');

            return self::SUCCESS;
        }

        $terminals = HikvisionTerminal::where('is_active', true)->get();

        foreach ($terminals as $terminal) {
            SyncHikvisionTerminalJob::dispatch($terminal, SyncRun::TRIGGER_SCHEDULE);
        }

        $this->info("Queued sync for {$terminals->count()} terminal(s).");

        return self::SUCCESS;
    }
}
