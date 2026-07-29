<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync-logs:prune {--days=30 : Number of days of logs to keep}')]
#[Description('Delete sync_logs records older than the given number of days')]
class PruneSyncLogs extends Command
{
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = SyncLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} sync log(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
