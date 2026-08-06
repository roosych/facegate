<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncAllJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /**
     * @return array<int, Event>
     */
    private function events(): array
    {
        return app(Schedule::class)->events();
    }

    private function findByExpression(string $needle, string $expression): bool
    {
        foreach ($this->events() as $event) {
            if ($event->expression === $expression && str_contains($event->getSummaryForDisplay(), $needle)) {
                return true;
            }
        }

        return false;
    }

    public function test_rusguard_is_fully_resynced_every_hour(): void
    {
        // This is the correctness floor: rusguard:poll-audit only reacts to the audit message
        // types it knows about, so without a periodic full resync anything outside that list
        // never reaches the local DB at all.
        $this->assertTrue(
            $this->findByExpression(SyncAllJob::class, '0 * * * *'),
            'An hourly SyncAllJob must stay scheduled — it is what makes the sync converge '
            .'independently of the audit message types poll-audit watches.'
        );
    }

    public function test_event_polling_looks_further_back_than_its_own_interval(): void
    {
        // A 30-minute schedule with a 15-minute look-back leaves half of every cycle unpolled.
        $this->assertTrue(
            $this->findByExpression('hikvision:fetch-events --minutes=35', '*/30 * * * *'),
            'The fetch-events look-back must exceed its 30-minute interval so no window is skipped.'
        );
    }
}
