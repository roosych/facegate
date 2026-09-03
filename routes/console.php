<?php

use App\Jobs\SyncAllJob;
use App\Models\SyncRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sync-logs:prune')->daily();
Schedule::command('model:prune', ['--model' => [SyncRun::class]])->daily();
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

// Read-only drift check: does every terminal slot still hold the person/card/face the local DB
// says it should? Catches a silently-scrambled device (dropped SetUp writes, a second env
// fighting for the same emp_code) in hours instead of by a "I passed as someone else" report.
// Exits non-zero on drift; wire an alert to ->emailOutputOnFailure() / a notifier as needed.
Schedule::command('hikvision:audit-terminal')->hourly();

// Correctness floor for RusGuard → local DB. poll-audit above reacts within a minute, but it
// can only react to the message types it knows about, so anything not on that list would
// otherwise never arrive; a new employee was measured sitting unsynced for three days. This
// re-reads RusGuard wholesale and converges regardless of what the audit log did or didn't
// report. Measured at 85.7s for 49 access points and 1005 employees, against a terminal push
// that already costs ~30s every 15 minutes — cheap enough to just always run. ShouldBeUnique
// on SyncAllJob drops this if one is already queued, and the 15-minute push above carries the
// results on to the terminals.
Schedule::job(new SyncAllJob(SyncRun::TRIGGER_SCHEDULE))->hourly();
