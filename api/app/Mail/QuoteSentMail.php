<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the requesting customer when staff send the priced quote. Line prices, notes and line text stay behind login. */
class QuoteSentMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $number,
        public string $total,
        public string $validUntil,
        public int $lineCount,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your quote {$this->number} is ready");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quotes.sent');
    }
}
