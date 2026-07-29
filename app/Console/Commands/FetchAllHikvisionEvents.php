<?php

namespace App\Console\Commands;

use App\Jobs\FetchHikvisionEventsJob;
use App\Models\HikvisionTerminal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hikvision:fetch-events {--minutes=15 : How many minutes back to fetch}')]
#[Description('Queue an event-fetch job for every active Hikvision terminal, covering the last N minutes')]
class FetchAllHikvisionEvents extends Command
{
    public function handle(): int
    {
        $endTime = now();
        $startTime = $endTime->copy()->subMinutes((int) $this->option('minutes'));

        $terminals = HikvisionTerminal::where('is_active', true)->get();

        foreach ($terminals as $terminal) {
            FetchHikvisionEventsJob::dispatch($terminal, $startTime, $endTime);
        }

        $this->info("Queued event fetch for {$terminals->count()} terminal(s).");

        return self::SUCCESS;
    }
}
