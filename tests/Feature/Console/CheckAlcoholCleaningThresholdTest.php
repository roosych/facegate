<?php

namespace Tests\Feature\Console;

use App\Mail\TerminalNeedsCleaningMail;
use App\Models\AccessEvent;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckAlcoholCleaningThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_emails_recipients_and_marks_the_terminal_notified_once_the_threshold_is_crossed(): void
    {
        Mail::fake();
        config(['alcohol.cleaning_threshold' => 2]);
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create();
        AccessEvent::factory(2)->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->artisan('alcohol:check-cleaning-threshold')->assertExitCode(0);

        Mail::assertQueued(TerminalNeedsCleaningMail::class, fn ($mail) => $mail->hasTo('security@example.com') && $mail->terminal->is($terminal));
        $this->assertNotNull($terminal->fresh()->alcohol_cleaning_notified_at);
    }

    public function test_does_not_notify_a_terminal_already_notified_for_this_cycle(): void
    {
        Mail::fake();
        config(['alcohol.cleaning_threshold' => 1]);
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create(['alcohol_cleaning_notified_at' => now()->subDay()]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->artisan('alcohol:check-cleaning-threshold')->assertExitCode(0);

        Mail::assertNothingOutgoing();
    }

    public function test_does_not_notify_below_the_threshold(): void
    {
        Mail::fake();
        config(['alcohol.cleaning_threshold' => 5]);
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create();
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->artisan('alcohol:check-cleaning-threshold')->assertExitCode(0);

        Mail::assertNothingOutgoing();
        $this->assertNull($terminal->fresh()->alcohol_cleaning_notified_at);
    }

    public function test_ignores_terminals_with_alcohol_detection_disabled(): void
    {
        Mail::fake();
        config(['alcohol.cleaning_threshold' => 1]);
        Setting::set('alcohol_notification_emails', 'security@example.com');

        $terminal = HikvisionTerminal::factory()->create();
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $this->artisan('alcohol:check-cleaning-threshold')->assertExitCode(0);

        Mail::assertNothingOutgoing();
    }
}
