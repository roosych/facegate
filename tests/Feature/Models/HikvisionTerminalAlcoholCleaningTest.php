<?php

namespace Tests\Feature\Models;

use App\Models\AccessEvent;
use App\Models\HikvisionTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HikvisionTerminalAlcoholCleaningTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_all_alcohol_tests_when_never_cleaned(): void
    {
        $terminal = HikvisionTerminal::factory()->create();
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'event_time' => now()->subYear(),
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['name' => 'plain card swipe'],
        ]);

        $this->assertSame(1, $terminal->alcoholTestCountSinceCleaning());
    }

    public function test_only_counts_tests_after_the_last_cleaning(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['alcohol_last_cleaned_at' => now()->subDay()]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'event_time' => now()->subWeek(),
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'event_time' => now(),
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->assertSame(1, $terminal->alcoholTestCountSinceCleaning());
    }

    public function test_needs_cleaning_once_the_threshold_is_reached(): void
    {
        config(['alcohol.cleaning_threshold' => 2]);
        $terminal = HikvisionTerminal::factory()->create();
        AccessEvent::factory(2)->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->assertTrue($terminal->needsAlcoholCleaning());
    }

    public function test_does_not_need_cleaning_below_the_threshold(): void
    {
        config(['alcohol.cleaning_threshold' => 5]);
        $terminal = HikvisionTerminal::factory()->create();
        AccessEvent::factory(2)->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->assertFalse($terminal->needsAlcoholCleaning());
    }

    public function test_mark_alcohol_cleaned_resets_the_count_and_notification_flag(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['alcohol_cleaning_notified_at' => now()->subHour()]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $terminal->markAlcoholCleaned();
        $terminal->refresh();

        $this->assertNotNull($terminal->alcohol_last_cleaned_at);
        $this->assertNull($terminal->alcohol_cleaning_notified_at);
        $this->assertSame(0, $terminal->alcoholTestCountSinceCleaning());
    }
}
