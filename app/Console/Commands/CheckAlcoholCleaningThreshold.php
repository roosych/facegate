<?php

namespace App\Console\Commands;

use App\Mail\TerminalNeedsCleaningMail;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('alcohol:check-cleaning-threshold')]
#[Description('Email the configured recipients when an alcohol-enabled Hikvision terminal has run enough tests since its last cleaning to cross the configured threshold')]
class CheckAlcoholCleaningThreshold extends Command
{
    public function handle(): int
    {
        $threshold = config('alcohol.cleaning_threshold');
        $recipients = Setting::alcoholCleaningNotificationEmails();
        $notified = 0;

        $terminals = HikvisionTerminal::where('is_active', true)
            ->whereNull('alcohol_cleaning_notified_at')
            ->get()
            ->filter(fn (HikvisionTerminal $terminal) => $terminal->resolvedAlcoholParams()['enabled']);

        foreach ($terminals as $terminal) {
            $count = $terminal->alcoholTestCountSinceCleaning();

            if ($count < $threshold) {
                continue;
            }

            if ($recipients !== []) {
                Mail::to($recipients)->send(new TerminalNeedsCleaningMail($terminal, $count, $threshold));
            }

            $terminal->update(['alcohol_cleaning_notified_at' => now()]);
            $notified++;
        }

        $this->info("Notified {$notified} terminal(s) over the cleaning threshold.");

        return self::SUCCESS;
    }
}
