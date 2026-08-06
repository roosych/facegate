<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sync-logs:prune')->daily();
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();
Schedule::command('alcohol:clear-expired-skip')->everyFiveMinutes();

// Real-time events now arrive via the Hikvision terminal's own push (HttpHostNotification →
// HikvisionEventWebhookController). This is just a safety net in case a terminal's push
// config lapses or it silently stops delivering — same ingest path either way, with dedup.
// The look-back must exceed the interval, otherwise every run leaves an unpolled gap behind
// it (the command's own default is 15 minutes, which covered only half of a 30-minute cycle);
// the overlap is free because ingest() deduplicates on the terminal's serialNo.
Schedule::command('hikvision:fetch-events', ['--minutes' => 35])->everyThirtyMinutes();

// These only enqueue jobs and return immediately, so withoutOverlapping() on the schedule
// protects nothing — the work itself is guarded by ShouldBeUnique on the jobs.
Schedule::command('hikvision:sync-all')->everyFifteenMinutes();
Schedule::command('rusguard:poll-audit')->everyMinute()->withoutOverlapping();
