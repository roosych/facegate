<?php

namespace App\Console\Commands;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hikvision:configure-webhook {terminal? : Terminal ID — omit to configure every active terminal}')]
#[Description("Point Hikvision terminal(s) at this deployment's real-time event webhook (config('hikvision.webhook_base_url'))")]
class HikvisionConfigureWebhook extends Command
{
    public function handle(): int
    {
        if (! config('hikvision.sync_enabled')) {
            $this->warn('Hikvision sync is disabled in this environment (config hikvision.sync_enabled). Refusing to repoint terminal push.');

            return self::SUCCESS;
        }

        if (! config('hikvision.webhook_base_url') || ! config('hikvision.webhook_token')) {
            $this->error('HIKVISION_WEBHOOK_BASE_URL and/or HIKVISION_WEBHOOK_TOKEN are not set in .env.');

            return self::FAILURE;
        }

        $terminals = $this->argument('terminal')
            ? HikvisionTerminal::where('id', $this->argument('terminal'))->get()
            : HikvisionTerminal::where('is_active', true)->get();

        if ($terminals->isEmpty()) {
            $this->error('No matching terminal(s) found.');

            return self::FAILURE;
        }

        foreach ($terminals as $terminal) {
            $ok = (new HikvisionService($terminal))->configureEventListening();

            $ok
                ? $this->info("[{$terminal->name}] webhook configured.")
                : $this->error("[{$terminal->name}] webhook configuration FAILED.");
        }

        return self::SUCCESS;
    }
}
