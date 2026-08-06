<?php

namespace App\Mail;

use App\Models\AccessEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlcoholTestFailedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly AccessEvent $event) {}

    public function envelope(): Envelope
    {
        $name = $this->event->employee?->full_name ?? 'Unknown employee';

        return new Envelope(
            subject: "Alcohol test failed — {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.alcohol-test-failed',
            with: [
                'employeeName' => $this->event->employee?->full_name ?? 'Unknown employee',
                'terminalName' => $this->event->hikvisionTerminal?->name ?? 'Unknown terminal',
                'eventTime' => $this->event->event_time,
                'concentration' => $this->event->alcoholConcentration(),
                'promille' => $this->event->alcoholPromille(),
                'result' => $this->event->raw_data['alcoholDetectionInfo']['result'] ?? 'unknown',
            ],
        );
    }
}
