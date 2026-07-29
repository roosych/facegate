<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sync-logs:prune')->daily();
Schedule::command('alcohol:clear-expired-skip')->everyFiveMinutes();
Schedule::command('hikvision:fetch-events')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('hikvision:sync-all')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('rusguard:poll-audit')->everyMinute()->withoutOverlapping();
