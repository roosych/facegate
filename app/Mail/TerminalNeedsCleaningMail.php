<?php

namespace App\Mail;

use App\Models\HikvisionTerminal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TerminalNeedsCleaningMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly HikvisionTerminal $terminal, public readonly int $testCount, public readonly int $threshold) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Требуется чистка/калибровка терминала — {$this->terminal->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.terminal-needs-cleaning',
            with: [
                'terminalName' => $this->terminal->name,
                'testCount' => $this->testCount,
                'threshold' => $this->threshold,
                'lastCleanedAt' => $this->terminal->alcohol_last_cleaned_at,
            ],
        );
    }
}
