<?php

namespace App\Console\Commands;

use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hikvision:sync-all')]
#[Description('Queue a sync job for every active Hikvision terminal (persons, cards, faces, alcohol skip flags)')]
class SyncAllHikvisionTerminals extends Command
{
    public function handle(): int
    {
        $terminals = HikvisionTerminal::where('is_active', true)->get();

        foreach ($terminals as $terminal) {
            SyncHikvisionTerminalJob::dispatch($terminal);
        }

        $this->info("Queued sync for {$terminals->count()} terminal(s).");

        return self::SUCCESS;
    }
}
