<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To staff when a customer submits a quote. Scalars only and no customer free text (notes, line text, file names). */
class QuoteSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public string $number,
        public string $companyName,
        public string $requesterName,
        public int $lineCount,
        public ?string $poReference,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New quote request {$this->number}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quotes.submitted');
    }
}
