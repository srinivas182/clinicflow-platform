<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Every email leaves from the platform's address; the provider's name is the
 * display name and replies go to the provider's reply-to address.
 */
class PlatformMessageMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $text,
        public string $fromAddress,
        public string $fromName,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->fromName)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5">'.nl2br(e($this->text)).'</div>');
    }
}
